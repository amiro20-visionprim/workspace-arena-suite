<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Services\PlanLimits;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * کاور خودکار (فاز D): پیش از ساخت فرمان انتشار، برای مقاله/محصول کاور تهیه می‌کند.
 *
 * زنجیرهٔ هوشمند (مطابق پروپوزال مصوب):
 *   ۱) دارایی متصل (media_assets با slot=cover که کاربر در استودیو انتخاب کرده)
 *   ۲) استوک حرفه‌ای با کلیدواژهٔ هدف (Pexels/Unsplash — تقریباً رایگان)
 *   ۳) تولید AI در صورت بودن کلید و سهمیه (openai-image)
 *   ۴) بدون کاور — گیت کیفیت طبق سیاست سازمان هشدار/جلوگیری می‌کند
 *
 * سپس تصویر با endpoint امضاشدهٔ /media پلاگین به رسانهٔ وردپرسِ مشتری آپلود
 * می‌شود (تصمیم مالک: ذخیرهٔ نهایی = کتابخانهٔ رسانهٔ وردپرس) و media_id
 * در payload فرمان می‌نشیند.
 */
class AutoCoverService
{
    public function __construct(
        private readonly ImageGateway $images,
    ) {}

    /**
     * @param  object|null  $connection  ردیف site_connections (اتصال پلاگین)
     * @return array{cover_media_id?: int|null, cover_url?: string|null, cover_alt?: string|null, source?: string, skipped_reason?: string}
     */
    public function provisionCover(Organization $org, ?object $connection, string $title, string $targetQuery, ?int $draftId = null, ?int $siteId = null): array
    {
        // خط خودکار generation دارد نه draft — draft_id فقط برای draftهای واقعی استودیو معتبر است.
        if ($draftId !== null && ! DB::table('content_drafts')->where('id', $draftId)->exists()) {
            $draftId = null;
        }

        $alt = mb_substr('کاور: '.$title, 0, 300);
        $keyword = trim($targetQuery) !== '' ? $targetQuery : $title;

        // ۱) دارایی متصلِ کاربر (استودیو)
        if ($draftId !== null) {
            $attached = DB::table('media_assets')
                ->where('organization_id', $org->getKey())
                ->where('draft_id', $draftId)
                ->where('slot', 'cover')
                ->latest()
                ->first();
            if ($attached !== null) {
                $uploaded = $this->uploadToWordPress($connection, $attached->url ?? null, $attached->path, (string) $attached->alt);
                if ($uploaded !== null) {
                    DB::table('media_assets')->where('id', $attached->id)->update(['wp_media_id' => $uploaded['media_id'], 'wp_url' => $uploaded['url'], 'updated_at' => now()]);

                    return ['cover_media_id' => $uploaded['media_id'], 'cover_url' => $uploaded['url'], 'cover_alt' => (string) $attached->alt, 'source' => (string) $attached->source];
                }
            }
        }

        if ($connection === null) {
            return ['skipped_reason' => 'no_connection'];
        }

        // ۲) استوک — ارزان و معتبر (پیش‌فرض)
        $stock = $this->images->searchStock($org, $keyword, perPage: 3);
        if (($stock['success'] ?? false) === true && ($stock['results'][0]['url'] ?? '') !== '') {
            $photo = $stock['results'][0];
            $assetId = $this->images->registerStockAsset($org, $photo + ['provider' => 'auto'], $draftId, 'cover');
            if ($siteId !== null) {
                DB::table('media_assets')->where('id', $assetId)->update(['site_id' => $siteId]);
            }
            $uploaded = $this->uploadToWordPress($connection, $photo['url'], null, $photo['alt'] ?: $alt);
            if ($uploaded !== null) {
                DB::table('media_assets')->where('id', $assetId)->update(['wp_media_id' => $uploaded['media_id'], 'wp_url' => $uploaded['url'], 'updated_at' => now()]);

                return ['cover_media_id' => $uploaded['media_id'], 'cover_url' => $uploaded['url'], 'cover_alt' => $photo['alt'] ?: $alt, 'source' => 'stock'];
            }
        }

        // ۳) تولید AI — اگر کلید باشد و سهمیه اجازه دهد (PlanLimits از مسیر generate اعمال می‌شود؛ اینجا دستی چک می‌کنیم)
        $quotaError = app(PlanLimits::class)->imageQuotaError($org);
        if ($quotaError === null) {
            $generated = $this->images->generate($org, 'featured cover image, professional, clean, for article: '.$title, ImageGateway::SIZE_COVER, $alt, $draftId);
            if (($generated['success'] ?? false) === true) {
                $path = (string) ($generated['path'] ?? '');
                $b64 = $path !== '' ? base64_encode((string) Storage::disk('local')->get($path)) : null;
                $uploaded = $this->uploadToWordPress($connection, $generated['url'] ?? null, null, $alt, $b64);
                if ($uploaded !== null) {
                    DB::table('media_assets')->where('id', $generated['asset_id'] ?? 0)->update(['wp_media_id' => $uploaded['media_id'], 'wp_url' => $uploaded['url'], 'updated_at' => now()]);

                    return ['cover_media_id' => $uploaded['media_id'], 'cover_url' => $uploaded['url'], 'cover_alt' => $alt, 'source' => 'ai'];
                }
            }
        }

        return ['skipped_reason' => 'no_source'];
    }

    /**
     * آپلود به رسانهٔ وردپرس با امضای HMAC (پلاگین v1.4 /media).
     *
     * @return array{media_id: int, url: string}|null
     */
    private function uploadToWordPress(?object $connection, ?string $url, ?string $localPath, string $alt, ?string $b64 = null): ?array
    {
        if ($connection === null) {
            return null;
        }

        try {
            $payload = ['alt' => $alt];
            if ($b64 !== null) {
                $payload['file_b64'] = $b64;
            } elseif ($url !== null && $url !== '') {
                $payload['download_url'] = $url;
            } elseif ($localPath !== null && $localPath !== '' && Storage::disk('local')->exists($localPath)) {
                $payload['file_b64'] = base64_encode((string) Storage::disk('local')->get($localPath));
            } else {
                return null;
            }

            $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
            $timestamp = (string) now()->timestamp;
            $nonce = (string) Str::uuid();
            $path = '/vision-prime/v1/media';
            $signature = hash_hmac('sha256', "POST\n{$path}\n{$timestamp}\n{$nonce}\n".hash('sha256', $body), Crypt::decryptString($connection->secret_ciphertext));

            $response = Http::timeout(60)->acceptJson()
                ->withHeaders(['X-VP-Timestamp' => $timestamp, 'X-VP-Nonce' => $nonce, 'X-VP-Signature' => $signature])
                ->withBody($body, 'application/json')
                ->post(rtrim((string) $connection->platform_url, '/').'/wp-json'.$path);

            if ($response->failed()) {
                Log::warning('auto-cover upload failed', ['status' => $response->status()]);

                return null;
            }

            $mediaId = (int) $response->json('media_id');
            $mediaUrl = (string) $response->json('url');

            return $mediaId > 0 ? ['media_id' => $mediaId, 'url' => $mediaUrl] : null;
        } catch (Throwable) {
            return null;
        }
    }
}
