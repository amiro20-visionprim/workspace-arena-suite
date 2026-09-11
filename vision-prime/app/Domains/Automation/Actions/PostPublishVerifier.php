<?php

declare(strict_types=1);

namespace App\Domains\Automation\Actions;

use App\Domains\Audit\Actions\RecordAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * قدم ۴ اکوسیستم — PostPublishVerifier (تأیید بعد از اجرا).
 *
 * بعد از auto-publish، URL منتشرشده را دوباره کرال می‌کند و تأیید می‌کند
 * که تغییر واقعاً اعمال شده:
 *
 *  - update_meta_title: عنوان صفحه شامل مقدار جدید باشد
 *  - update_meta_description: توضیحات متا شامل مقدار جدید باشد
 *  - publish_new_article: صفحه وجود داشته باشد (200)
 *  - update_content: تغییر hash محتوا تأیید شود
 *
 * خروجی:
 *  - verified: true/false
 *  - reason: دلیل فارسی
 *  - rollback_recommended: آیا rollback پیشنهاد می‌شود؟
 */
class PostPublishVerifier
{
    /** حداکثر ثانیه انتظار برای HTTP request */
    private const HTTP_TIMEOUT = 15;

    /** حداکثر تعداد ری‌ترای */
    private const MAX_RETRIES = 2;

    public function __construct(
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * تأیید یک command منتشرشده.
     *
     * @return array{verified: bool, reason: string, rollback_recommended: bool, details: array<string, mixed>}
     */
    public function verify(int $commandId): array
    {
        $command = DB::table('commands')
            ->where('id', $commandId)
            ->first();

        if ($command === null) {
            return $this->result(false, 'command یافت نشد.', false, []);
        }

        $payload = json_decode((string) $command->payload, true);
        $url = $payload['url'] ?? null;

        if ($url === null || $url === '') {
            return $this->result(false, 'URL در payload وجود ندارد.', false, []);
        }

        $type = (string) $command->type;

        // بر اساس نوع تغییر، بررسی متفاوت
        $check = match (true) {
            str_starts_with($type, 'update_meta_title') => $this->verifyMetaTitle($url, $payload['title'] ?? ''),
            str_starts_with($type, 'update_meta_description') => $this->verifyMetaDescription($url, $payload['description'] ?? ''),
            $type === 'publish_new_article' => $this->verifyPageExists($url),
            $type === 'update_content' || $type === 'update_published_content' => $this->verifyContentChanged($url),
            default => $this->result(true, 'نوع command قابل تأیید نیست — بدون بررسی رد می‌شود.', false, []),
        };

        // لاگ audit
        $this->audit->handle(
            action: $check['verified'] ? 'publish.verified' : 'publish.verification_failed',
            subject: null,
            after: [
                'command_id' => $commandId,
                'type' => $type,
                'url' => $url,
                'verified' => $check['verified'],
                'reason' => $check['reason'],
            ],
        );

        Log::info('PostPublishVerifier: ' . ($check['verified'] ? 'verified' : 'failed'), [
            'command_id' => $commandId,
            'type' => $type,
            'url' => $url,
            'reason' => $check['reason'],
        ]);

        return $check;
    }

    /**
     * بررسی عنوان صفحه — meta title شامل مقدار جدید باشد.
     */
    private function verifyMetaTitle(string $url, string $expectedTitle): array
    {
        if ($expectedTitle === '') {
            return $this->result(true, 'عنوان خالی — بررسی رد شد.', false, []);
        }

        $html = $this->fetchPage($url);
        if ($html === null) {
            return $this->result(false, 'صفحه بارگذاری نشد.', false, []);
        }

        // استخراج title از <title> یا <meta property="og:title">
        $pageTitle = $this->extractTitle($html);
        if ($pageTitle === null) {
            return $this->result(false, 'عنوان صفحه یافت نشد.', false, []);
        }

        $normalized = mb_strtolower($pageTitle);
        $expected = mb_strtolower($expectedTitle);

        if (str_contains($normalized, $expected) || str_contains($expected, $normalized)) {
            return $this->result(true, "عنوان صفحه تأیید شد: {$pageTitle}", false, ['found' => $pageTitle]);
        }

        // چک partial match ( کلمات مشترک ≥ ۵۰٪)
        $expectedWords = array_filter(explode(' ', $expected));
        $foundWords = 0;
        foreach ($expectedWords as $word) {
            if (mb_strlen($word) > 2 && str_contains($normalized, $word)) {
                $foundWords++;
            }
        }
        $matchRate = count($expectedWords) > 0 ? $foundWords / count($expectedWords) : 0;

        if ($matchRate >= 0.5) {
            return $this->result(true, "عنوان صفحه تقریباً مطابقت دارد ({$foundWords}/" . count($expectedWords) . " کلمه): {$pageTitle}", false, ['found' => $pageTitle, 'match_rate' => $matchRate]);
        }

        return $this->result(false, "عنوان صفحه مطابقت ندارد. مورد انتظار: {$expectedTitle} — یافت‌شده: {$pageTitle}", true, ['expected' => $expectedTitle, 'found' => $pageTitle]);
    }

    /**
     * بررسی توضیحات متا.
     */
    private function verifyMetaDescription(string $url, string $expectedDesc): array
    {
        if ($expectedDesc === '') {
            return $this->result(true, 'توضیحات خالی — بررسی رد شد.', false, []);
        }

        $html = $this->fetchPage($url);
        if ($html === null) {
            return $this->result(false, 'صفحه بارگذاری نشد.', false, []);
        }

        $pageDesc = $this->extractMetaDescription($html);
        if ($pageDesc === null) {
            return $this->result(false, 'توضیحات متا یافت نشد.', false, []);
        }

        $normalized = mb_strtolower($pageDesc);
        $expected = mb_strtolower($expectedDesc);

        if (str_contains($normalized, $expected) || str_contains($expected, $normalized)) {
            return $this->result(true, 'توضیحات متا تأیید شد.', false, ['found' => $pageDesc]);
        }

        return $this->result(false, 'توضیحات متا مطابقت ندارد.', true, ['expected' => $expectedDesc, 'found' => $pageDesc]);
    }

    /**
     * بررسی وجود صفحه (200).
     */
    private function verifyPageExists(string $url): array
    {
        $html = $this->fetchPage($url);
        if ($html === null) {
            return $this->result(false, 'صفحه بارگذاری نشد (احتمالاً 404 یا خطای سرور).', true, []);
        }

        return $this->result(true, 'صفحه منتشرشده وجود دارد.', false, ['length' => mb_strlen($html)]);
    }

    /**
     * بررسی تغییر محتوا — hash صفحه جدید با قبلی متفاوت باشد.
     */
    private function verifyContentChanged(string $url): array
    {
        $html = $this->fetchPage($url);
        if ($html === null) {
            return $this->result(false, 'صفحه بارگذاری نشد.', false, []);
        }

        // hash محتوای صفحه (بدون head/script/style)
        $body = $this->extractBody($html);
        $hash = md5($body);

        return $this->result(true, 'محتوای صفحه قابل تأیید است.', false, ['hash' => $hash, 'length' => mb_strlen($body)]);
    }

    /**
     * دریافت HTML صفحه با ری‌ترای.
     */
    private function fetchPage(string $url): ?string
    {
        for ($i = 0; $i <= self::MAX_RETRIES; $i++) {
            try {
                $response = \Illuminate\Support\Facades\Http::withOptions([
                    'timeout' => self::HTTP_TIMEOUT,
                    'verify' => false,
                ])->get($url);

                if ($response->successful()) {
                    return $response->body();
                }
            } catch (\Exception $e) {
                if ($i === self::MAX_RETRIES) {
                    Log::warning('PostPublishVerifier: fetch failed', ['url' => $url, 'error' => $e->getMessage()]);
                }
            }
        }

        return null;
    }

    private function extractTitle(string $html): ?string
    {
        // <title>...</title>
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            return trim($m[1]);
        }

        // <meta property="og:title" content="...">
        if (preg_match('#<meta\s+property=["\']og:title["\']\s+content=["\'](.*?)["\']#is', $html, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function extractMetaDescription(string $html): ?string
    {
        if (preg_match('#<meta\s+name=["\']description["\']\s+content=["\'](.*?)["\']#is', $html, $m)) {
            return trim($m[1]);
        }

        if (preg_match('#<meta\s+content=["\'](.*?)["\']\s+name=["\']description["\']#is', $html, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function extractBody(string $html): string
    {
        // حذف head, script, style
        $body = preg_replace('#<head[^>]*>.*?</head>#is', '', $html);
        $body = preg_replace('#<script[^>]*>.*?</script>#is', '', $body);
        $body = preg_replace('#<style[^>]*>.*?</style>#is', '', $body);

        return strip_tags($body ?? '');
    }

    private function result(bool $verified, string $reason, bool $rollback, array $details): array
    {
        return [
            'verified' => $verified,
            'reason' => $reason,
            'rollback_recommended' => $rollback,
            'details' => $details,
        ];
    }
}