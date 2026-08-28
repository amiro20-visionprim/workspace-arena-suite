<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Organization\Models\Organization;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * موتور تصویر (Image Engine) — سه منبع با اولویت هوشمند:
 *
 *   ۱) تولید AI (کلید سازمان؛ openai-image سازگار) — کاور برندشده و شات محصول
 *   ۲) استوک حرفه‌ای (Pexels → Unsplash) — مقالات؛ تقریباً رایگان
 *   ۳) پیشنهاد + جای‌نگهدار (بدون شبکه) — همیشه در دسترس
 *
 * هر دارایی در media_assets ثبت می‌شود: alt فارسی، اعتبار، هزینهٔ تخمینی.
 * جستجوی استوک با کلیدواژهٔ فارسی، از طریق مدل زبانیِ متن به انگلیسی ترجمه
 * می‌شود (ارزان‌ترین تماس ممکن) و در نبود مدل، همان کلیدواژه استفاده می‌شود.
 */
class ImageGateway
{
    public const SIZE_COVER = '1536x1024';   // ۳:۲ افقی — کاور/og:image

    public const SIZE_SECTION = '1024x1024'; // مربع — تصویر میانی

    /** هزینهٔ تخمینی هر تولید AI به دلار (برای داشبورد هزینه) */
    private const AI_COST_PER_IMAGE = 0.04;

    /**
     * سرویس‌هایی که در UI قابل تنظیم‌اند.
     *
     * @return array<int, array{key: string, label: string, kind: string, hint: string}>
     */
    public function availableProviders(): array
    {
        return [
            ['key' => 'openai-image', 'label' => 'OpenAI Image (gpt-image-1)', 'kind' => 'ai', 'hint' => 'تولید اختصاصی؛ کلید از platform.openai.com'],
            ['key' => 'pexels', 'label' => 'Pexels (استوک)', 'kind' => 'stock', 'hint' => 'رایگان — کلید از pexels.com/api'],
            ['key' => 'unsplash', 'label' => 'Unsplash (استوک)', 'kind' => 'stock', 'hint' => 'رایگان — Access Key از unsplash.com/developers'],
        ];
    }

    /** تنظیم رمزگشایی‌شدهٔ یک سرویس سازمان. */
    public function configFor(Organization $org, string $provider): array
    {
        $row = \DB::table('image_provider_settings')
            ->where('organization_id', $org->getKey())
            ->where('provider', $provider)
            ->where('status', 'active')
            ->first();

        if ($row === null) {
            return [];
        }

        return (array) json_decode((string) Crypt::decryptString($row->encrypted_config), true);
    }

    /** تست اتصال هر سرویس — پاسخ همیشه {success, error?}. */
    public function testConnection(string $provider, string $apiKey): array
    {
        if ($apiKey === '') {
            return ['success' => false, 'error' => 'کلید API خالی است.'];
        }

        try {
            return match ($provider) {
                'openai-image' => $this->testOpenAiImage($apiKey),
                'pexels' => $this->testPexels($apiKey),
                'unsplash' => $this->testUnsplash($apiKey),
                default => ['success' => false, 'error' => "سرویس تصویر ناشناخته: {$provider}"],
            };
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * تولید تصویر با AI — خروجی دارایی ثبت‌شده در media_assets.
     *
     * @return array{success: bool, asset_id?: int, url?: string|null, error?: string}
     */
    public function generate(Organization $org, string $prompt, string $size = self::SIZE_SECTION, ?string $alt = null, ?int $draftId = null): array
    {
        $config = $this->configFor($org, 'openai-image');
        $apiKey = (string) ($config['api_key'] ?? '');

        if ($apiKey === '') {
            return ['success' => false, 'error' => 'سرویس تولید تصویر تنظیم نشده است. از تنظیمات ← یکپارچه‌سازی‌ها کلید OpenAI Image را ثبت کنید.', 'needs_setup' => true];
        }

        $model = (string) ($config['model'] ?? 'gpt-image-1');
        $response = Http::timeout(120)
            ->withToken($apiKey)
            ->post('https://api.openai.com/v1/images/generations', [
                'model' => $model,
                'prompt' => mb_substr($prompt, 0, 900),
                'size' => in_array($size, [self::SIZE_COVER, self::SIZE_SECTION, '1024x1536'], true) ? $size : self::SIZE_SECTION,
                'n' => 1,
            ]);

        if ($response->failed()) {
            $msg = (string) ($response->json('error.message') ?? $response->body());
            Log::warning('image-generate failed', ['status' => $response->status()]);

            return ['success' => false, 'error' => 'تولید تصویر ناموفق بود: '.mb_substr($msg, 0, 200)];
        }

        $data = (array) $response->json('data.0');
        $b64 = (string) ($data['b64_json'] ?? '');
        $url = (string) ($data['url'] ?? '');
        $path = null;

        if ($b64 !== '') {
            $path = 'image-engine/'.now()->format('Ymd-His').'-'.Str::random(8).'.png';
            Storage::disk('local')->put($path, base64_decode($b64) ?: '');
        }

        $assetId = \DB::table('media_assets')->insertGetId([
            'organization_id' => $org->getKey(),
            'draft_id' => $draftId,
            'source' => 'ai',
            'provider' => 'openai-image',
            'url' => $url !== '' ? $url : null,
            'path' => $path,
            'alt' => mb_substr($alt ?: $prompt, 0, 300),
            'keywords' => mb_substr($prompt, 0, 300),
            'cost_estimate' => self::AI_COST_PER_IMAGE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['success' => true, 'asset_id' => $assetId, 'url' => $url !== '' ? $url : null, 'path' => $path];
    }

    /**
     * جستجوی استوک — Pexels سپس Unsplash؛ کلیدواژهٔ فارسی به انگلیسی ترجمه می‌شود.
     *
     * @return array{success: bool, results?: array<int, array{url: string, thumb: string, alt: string, credit: string, width: int|null, height: int-null}>, error?: string}
     */
    public function searchStock(Organization $org, string $query, int $perPage = 12): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['success' => false, 'error' => 'کلیدواژه خالی است.'];
        }

        $english = $this->toEnglishQuery($org, $query);

        // Pexels
        $pexelsKey = (string) ($this->configFor($org, 'pexels')['api_key'] ?? '');
        if ($pexelsKey !== '') {
            $r = Http::timeout(20)->withHeaders(['Authorization' => $pexelsKey])
                ->get('https://api.pexels.com/v1/search', ['query' => $english, 'per_page' => $perPage]);
            if ($r->ok()) {
                $results = [];
                foreach ((array) ($r->json('photos') ?? []) as $p) {
                    $results[] = [
                        'url' => (string) ($p['src']['large2x'] ?? $p['src']['large'] ?? ''),
                        'thumb' => (string) ($p['src']['medium'] ?? $p['src']['small'] ?? ''),
                        'alt' => (string) ($p['alt'] ?? $english),
                        'credit' => 'Photo: '.($p['photographer'] ?? 'Pexels').' / Pexels',
                        'width' => $p['width'] ?? null,
                        'height' => $p['height'] ?? null,
                        'provider' => 'pexels',
                    ];
                }
                if ($results !== []) {
                    return ['success' => true, 'results' => $results, 'translated_query' => $english];
                }
            }
        }

        // Unsplash
        $unsplashKey = (string) ($this->configFor($org, 'unsplash')['api_key'] ?? '');
        if ($unsplashKey !== '') {
            $r = Http::timeout(20)->get('https://api.unsplash.com/search/photos', [
                'query' => $english, 'per_page' => $perPage, 'client_id' => $unsplashKey,
            ]);
            if ($r->ok()) {
                $results = [];
                foreach ((array) ($r->json('results') ?? []) as $p) {
                    $results[] = [
                        'url' => (string) ($p['urls']['regular'] ?? ''),
                        'thumb' => (string) ($p['urls']['small'] ?? ''),
                        'alt' => (string) ($p['alt_description'] ?? $english),
                        'credit' => 'Photo: '.($p['user']['name'] ?? '').' / Unsplash',
                        'width' => $p['width'] ?? null,
                        'height' => $p['height'] ?? null,
                        'provider' => 'unsplash',
                    ];
                }
                if ($results !== []) {
                    return ['success' => true, 'results' => $results, 'translated_query' => $english];
                }
            }
        }

        return [
            'success' => false,
            'error' => 'سرویس استوک فعال نیست. کلید رایگان Pexels را از تنظیمات ← یکپارچه‌سازی‌ها ثبت کنید.',
            'needs_setup' => true,
        ];
    }

    /**
     * پیشنهاد جای‌نگهدار (بدون شبکه): alt فارسی + کلیدواژهٔ پیشنهادی + نسبت.
     *
     * @return array<int, array{alt: string, query: string, aspect: string}>
     */
    public function suggestions(string $title, string $section = ''): array
    {
        $base = trim($title.' '.trim($section));

        return [
            ['alt' => $base.' — تصویر شاخص', 'query' => $base, 'aspect' => '16:9'],
            ['alt' => 'نمایش کاربردی '.$base, 'query' => $base.' کاربرد', 'aspect' => '4:3'],
        ];
    }

    /** ثبت دارایی استوک انتخاب‌شده در media_assets. */
    public function registerStockAsset(Organization $org, array $photo, ?int $draftId = null, ?string $slot = null): int
    {
        return \DB::table('media_assets')->insertGetId([
            'organization_id' => $org->getKey(),
            'draft_id' => $draftId,
            'source' => 'stock',
            'provider' => (string) ($photo['provider'] ?? 'pexels'),
            'url' => (string) ($photo['url'] ?? ''),
            'alt' => mb_substr((string) ($photo['alt'] ?? ''), 0, 300),
            'credit' => mb_substr((string) ($photo['credit'] ?? ''), 0, 200),
            'width' => $photo['width'] ?? null,
            'height' => $photo['height'] ?? null,
            'slot' => $slot,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** ترجمهٔ ارزان کلیدواژهٔ فارسی به انگلیسی برای جستجوی استوک. */
    private function toEnglishQuery(Organization $org, string $query): string
    {
        try {
            $result = app(AiGateway::class)->generate(
                'تو یک مترجم کلیدواژهٔ جستجوی تصویر هستی. عبارت فارسی را به یک عبارت جستجوی تصویری کوتاه انگلیسی (۲ تا ۵ کلمه) تبدیل کن. فقط خود عبارت را بنویس.',
                $query,
                'meta_title',
            );

            $text = trim((string) ($result['content'] ?? ''));
            if ($text !== '' && mb_strlen($text) < 80 && ! preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
                return $text;
            }
        } catch (\Throwable) {
            // بدون مدل متن → همان کلیدواژه
        }

        return $query;
    }

    private function testOpenAiImage(string $apiKey): array
    {
        $r = Http::timeout(30)->withToken($apiKey)
            ->get('https://api.openai.com/v1/models');

        return $r->ok()
            ? ['success' => true]
            : ['success' => false, 'error' => 'پاسخ OpenAI: '.$r->status().' — '.mb_substr((string) ($r->json('error.message') ?? ''), 0, 150)];
    }

    private function testPexels(string $apiKey): array
    {
        $r = Http::timeout(20)->withHeaders(['Authorization' => $apiKey])
            ->get('https://api.pexels.com/v1/search', ['query' => 'nature', 'per_page' => 1]);

        return $r->ok()
            ? ['success' => true]
            : ['success' => false, 'error' => 'پاسخ Pexels: '.$r->status()];
    }

    private function testUnsplash(string $apiKey): array
    {
        $r = Http::timeout(20)->get('https://api.unsplash.com/search/photos', [
            'query' => 'nature', 'per_page' => 1, 'client_id' => $apiKey,
        ]);

        return $r->ok()
            ? ['success' => true]
            : ['success' => false, 'error' => 'پاسخ Unsplash: '.$r->status()];
    }
}
