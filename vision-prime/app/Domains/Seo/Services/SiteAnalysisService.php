<?php

declare(strict_types=1);

namespace App\Domains\Seo\Services;

use App\Domains\Workspace\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * لایه ۲ — تحلیل عمیق صفحات.
 *
 * بعد از کرال هر شب، این سرویس داده‌های url_profiles رو تحلیل عمیق میکنه:
 *   ۱) گراف لینک داخلی + شناسایی صفحات یتیم (orphan)
 *   ۲) عمق صفحه (BFS از صفحه اصلی)
 *   ۳) امتیاز تازگی محتوا
 *   ۴) تراکم کلمات کلیدی
 *
 * خروجی: فرصت‌ها و ریسک‌های جدید در static_opportunities / static_risk_patterns
 */
class SiteAnalysisService
{
    /** حداکثر عمق قابل قبول */
    private const MAX_ACCEPTABLE_DEPTH = 3;

    /** حداقل تازگی (روز) — صفحات قدیمی‌تر از این ریسک دارن */
    private const FRESHNESS_THRESHOLD_DAYS = 90;

    /** حداقل تراکم کلمه کلیدی (%) */
    private const MIN_KEYWORD_DENSITY = 1.0;

    /** حداکثر تراکم کلمه کلیدی (%) — keyword stuffing */
    private const MAX_KEYWORD_DENSITY = 3.0;

    /**
     * اجرای کامل تحلیل برای یک سایت.
     *
     * @return array{orphan_pages: int, deep_pages: int, stale_pages: int, keyword_issues: int}
     */
    public function analyze(Site $site): array
    {
        $start = microtime(true);
        Log::info(self::class.' starting analysis', ['site_id' => $site->id]);

        $profiles = DB::table('url_profiles')
            ->where('site_id', $site->id)
            ->get();

        if ($profiles->isEmpty()) {
            return ['orphan_pages' => 0, 'deep_pages' => 0, 'stale_pages' => 0, 'keyword_issues' => 0];
        }

        // ۱) گراف لینک + صفحات یتیم
        $orphanCount = $this->detectOrphanPages($site, $profiles);

        // ۲) عمق صفحه
        $deepCount = $this->analyzePageDepth($site, $profiles);

        // ۳) تازگی محتوا
        $staleCount = $this->analyzeFreshness($site, $profiles);

        // ۴) تراکم کلمات کلیدی
        $keywordIssues = $this->analyzeKeywordDensity($site, $profiles);

        $elapsed = round(microtime(true) - $start, 1);
        Log::info(self::class.' analysis completed', [
            'site_id' => $site->id,
            'orphan' => $orphanCount, 'deep' => $deepCount,
            'stale' => $staleCount, 'keyword_issues' => $keywordIssues,
            'duration' => $elapsed,
        ]);

        return [
            'orphan_pages' => $orphanCount,
            'deep_pages' => $deepCount,
            'stale_pages' => $staleCount,
            'keyword_issues' => $keywordIssues,
        ];
    }

    /**
     * ۱) گراف لینک داخلی + شناسایی صفحات یتیم.
     * صفحه یتیم = صفحه‌ای که هیچ لینک داخلی بهش اشاره نمیکنه.
     */
    private function detectOrphanPages(Site $site, $profiles): int
    {
        $allUrls = [];
        $linkedUrls = [];

        foreach ($profiles as $profile) {
            $url = rtrim($profile->canonical_url, '/');
            $allUrls[$url] = $profile->id;

            $meta = json_decode($profile->metadata, true) ?? [];
            $linkTargets = $meta['link_targets'] ?? [];

            foreach ($linkTargets as $target) {
                $normalizedTarget = $this->normalizeUrl($target);
                if (! isset($linkedUrls[$normalizedTarget])) {
                    $linkedUrls[$normalizedTarget] = 0;
                }
                $linkedUrls[$normalizedTarget]++;
            }
        }

        $orphanCount = 0;
        foreach ($allUrls as $url => $profileId) {
            $normalizedUrl = $this->normalizeUrl($url);
            if (! isset($linkedUrls[$normalizedUrl]) || $linkedUrls[$normalizedUrl] === 0) {
                // صفحه یتیم — فرصت لینک‌سازی داخلی
                DB::table('static_opportunities')->updateOrInsert(
                    [
                        'site_id' => $site->id,
                        'url_profile_id' => $profileId,
                        'type' => 'interlink_opportunity',
                        'source' => 'analyzer_orphan',
                        'keyword_suggested' => 'orphan_page',
                    ],
                    [
                        'title' => 'صفحه یتیم — نیاز به لینک داخلی',
                        'score' => 85,
                        'confidence' => 0.95,
                        'status' => 'open',
                        'explanation' => "صفحه «{$url}» هیچ لینک داخلی ورودی ندارد — لینک‌سازی داخلی توصیه می‌شود",
                        'metadata' => json_encode([
                            'source' => 'analyzer', 'type' => 'orphan',
                            'incoming_links' => 0, 'url' => $url,
                        ], JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $orphanCount++;
            }
        }

        return $orphanCount;
    }

    /**
     * ۲) تحلیل عمق صفحه با BFS از صفحه اصلی.
     * صفحاتی که بیشتر از MAX_ACCEPTABLE_DEPTH کلیک با صفحه اصلی فاصله دارن = ضعیف.
     */
    private function analyzePageDepth(Site $site, $profiles): int
    {
        // ساخت گراف邻接ی
        $adjacency = [];
        $urlToProfile = [];

        foreach ($profiles as $profile) {
            $url = $this->normalizeUrl($profile->canonical_url);
            $urlToProfile[$url] = $profile->id;

            $meta = json_decode($profile->metadata, true) ?? [];
            $linkTargets = $meta['link_targets'] ?? [];
            $adjacency[$url] = [];
            foreach ($linkTargets as $target) {
                $adjacency[$url][] = $this->normalizeUrl($target);
            }
        }

        // BFS از صفحه اصلی
        $startUrl = $this->normalizeUrl(rtrim((string) $site->canonical_url, '/').'/');
        $depth = [$startUrl => 0];
        $queue = [$startUrl];
        $visited = [$startUrl => true];

        while (! empty($queue)) {
            $current = array_shift($queue);
            $currentDepth = $depth[$current];

            foreach (($adjacency[$current] ?? []) as $neighbor) {
                if (isset($visited[$neighbor])) {
                    continue;
                }
                $visited[$neighbor] = true;
                $depth[$neighbor] = $currentDepth + 1;
                $queue[] = $neighbor;
            }
        }

        // شناسایی صفحات عمیق
        $deepCount = 0;
        foreach ($depth as $url => $d) {
            if ($d > self::MAX_ACCEPTABLE_DEPTH && isset($urlToProfile[$url])) {
                DB::table('static_opportunities')->updateOrInsert(
                    [
                        'site_id' => $site->id,
                        'url_profile_id' => $urlToProfile[$url],
                        'type' => 'content_gap',
                        'source' => 'analyzer_depth',
                        'keyword_suggested' => 'deep_page',
                    ],
                    [
                        'title' => "صفحه عمیق — عمق {$d} کلیک",
                        'score' => 70,
                        'confidence' => 0.8,
                        'status' => 'open',
                        'explanation' => "صفحه «{$url}» در عمق {$d} کلیک از صفحه اصلی قرار دارد — بهتر است لینک‌سازی داخلی بهبود یابد",
                        'metadata' => json_encode([
                            'source' => 'analyzer', 'type' => 'deep_page',
                            'depth' => $d, 'max_acceptable' => self::MAX_ACCEPTABLE_DEPTH, 'url' => $url,
                        ], JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $deepCount++;
            }
        }

        // صفحات غیرقابل دسترس (reach نشده)
        foreach ($urlToProfile as $url => $profileId) {
            if (! isset($depth[$url])) {
                DB::table('static_opportunities')->updateOrInsert(
                    [
                        'site_id' => $site->id,
                        'url_profile_id' => $profileId,
                        'type' => 'interlink_opportunity',
                        'source' => 'analyzer_unreachable',
                        'keyword_suggested' => 'unreachable',
                    ],
                    [
                        'title' => 'صفحه غیرقابل دسترس از صفحه اصلی',
                        'score' => 90,
                        'confidence' => 0.95,
                        'status' => 'open',
                        'explanation' => "صفحه «{$url}» از طریق لینک‌سازی داخلی قابل دسترس نیست",
                        'metadata' => json_encode([
                            'source' => 'analyzer', 'type' => 'unreachable', 'url' => $url,
                        ], JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $deepCount++;
            }
        }

        return $deepCount;
    }

    /**
     * ۳) تحلیل تازگی محتوا.
     * صفحاتی که بیشتر از FRESHNESS_THRESHOLD_DAYS روز پیش آپدیت شدن = نیاز به بروزرسانی.
     */
    private function analyzeFreshness(Site $site, $profiles): int
    {
        $staleCount = 0;
        $now = now();

        foreach ($profiles as $profile) {
            $meta = json_decode($profile->metadata, true) ?? [];
            $crawledAt = $meta['crawled_at'] ?? null;
            $updatedAt = $profile->updated_at ?? null;

            $lastUpdate = $updatedAt ? \Carbon\Carbon::parse($updatedAt) : ($crawledAt ? \Carbon\Carbon::parse($crawledAt) : null);
            if ($lastUpdate === null) {
                continue;
            }

            $daysSince = $now->diffInDays($lastUpdate);
            if ($daysSince > self::FRESHNESS_THRESHOLD_DAYS) {
                $freshnessScore = max(0, 100 - (($daysSince - self::FRESHNESS_THRESHOLD_DAYS) / 30 * 10));

                DB::table('static_opportunities')->updateOrInsert(
                    [
                        'site_id' => $site->id,
                        'url_profile_id' => $profile->id,
                        'type' => 'content_gap',
                        'source' => 'analyzer_freshness',
                        'keyword_suggested' => 'stale_content',
                    ],
                    [
                        'title' => "محتوای قدیمی — {$daysSince} روز بدون بروزرسانی",
                        'score' => (int) $freshnessScore,
                        'confidence' => 0.85,
                        'status' => 'open',
                        'explanation' => "صفحه «{$profile->canonical_url}» {$daysSince} روز است بروزرسانی نشده — بروزرسانی محتوا توصیه می‌شود",
                        'metadata' => json_encode([
                            'source' => 'analyzer', 'type' => 'stale',
                            'days_since_update' => $daysSince, 'url' => $profile->canonical_url,
                        ], JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $staleCount++;
            }
        }

        return $staleCount;
    }

    /**
     * ۴) تحلیل تراکم کلمات کلیدی.
     * بررسی میکنه آیا کلمه کلیدی اصلی (از عنوان صفحه) در محتوا تکرار مناسبی داره.
     */
    private function analyzeKeywordDensity(Site $site, $profiles): int
    {
        $issues = 0;

        foreach ($profiles as $profile) {
            $meta = json_decode($profile->metadata, true) ?? [];
            $title = $meta['title'] ?? '';
            $headings = $meta['headings'] ?? [];
            $h1List = $headings['h1'] ?? [];
            $wordCount = $meta['word_count'] ?? 0;

            if ($title === '' || $wordCount < 100) {
                continue;
            }

            // استخراج کلمه کلیدی اصلی (اولین کلمه معنادار عنوان)
            $keywords = $this->extractKeywords($title);
            if (empty($keywords)) {
                continue;
            }

            $mainKeyword = $keywords[0];
            $keywordLength = mb_strlen($mainKeyword, 'UTF-8');
            if ($keywordLength < 4) {
                continue; // کلمات خیلی کوتاه
            }

            // شمارش تکرار کلمه کلیدی در metadata (ساده)
            $fullText = json_encode($meta, JSON_UNESCAPED_UNICODE);
            $occurrences = mb_substr_count(mb_strtolower($fullText, 'UTF-8'), mb_strtolower($mainKeyword, 'UTF-8'), 'UTF-8');
            $density = $wordCount > 0 ? ($occurrences / $wordCount) * 100 : 0;

            if ($density < self::MIN_KEYWORD_DENSITY && $wordCount > 300) {
                DB::table('static_opportunities')->updateOrInsert(
                    [
                        'site_id' => $site->id,
                        'url_profile_id' => $profile->id,
                        'type' => 'keyword_opportunity',
                        'source' => 'analyzer_density_low',
                        'keyword_suggested' => $mainKeyword,
                    ],
                    [
                        'title' => "تراکم پایین کلمه کلیدی «{$mainKeyword}»",
                        'score' => 65,
                        'confidence' => 0.7,
                        'status' => 'open',
                        'explanation' => sprintf('کلمه کلیدی «%s» فقط %.1f%% تراکم دارد (حداقل %.1f%% توصیه می‌شود)', $mainKeyword, $density, self::MIN_KEYWORD_DENSITY),
                        'metadata' => json_encode([
                            'source' => 'analyzer', 'type' => 'low_density',
                            'keyword' => $mainKeyword, 'density' => round($density, 2),
                            'occurrences' => $occurrences, 'url' => $profile->canonical_url,
                        ], JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $issues++;
            }

            if ($density > self::MAX_KEYWORD_DENSITY) {
                DB::table('static_risk_patterns')->updateOrInsert(
                    [
                        'site_id' => $site->id,
                        'url_profile_id' => $profile->id,
                        'key' => 'keyword_stuffing',
                    ],
                    [
                        'severity' => 'high',
                        'status' => 'open',
                        'explanation' => sprintf('کلمه کلیدی «%s» با تراکم %.1f%% احتمال keyword stuffing دارد (حداکثر %.1f%%)', $mainKeyword, $density, self::MAX_KEYWORD_DENSITY),
                        'metadata' => json_encode([
                            'source' => 'analyzer', 'keyword' => $mainKeyword,
                            'density' => round($density, 2), 'url' => $profile->canonical_url,
                        ], JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $issues++;
            }
        }

        return $issues;
    }

    /**
     * استخراج کلمات کلیدی معنادار از عنوان.
     */
    private function extractKeywords(string $title): array
    {
        // حذف کلمات ایست (فارسی + انگلیسی)
        $stopWords = ['و', 'در', 'به', 'از', 'با', 'برای', 'که', 'این', 'آن', 'را', 'است', 'شد', 'شده',
            'the', 'a', 'an', 'is', 'are', 'was', 'were', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'and', 'or'];

        $words = preg_split('/[\s\-,\.]+/', $title, -1, PREG_SPLIT_NO_EMPTY);
        $keywords = [];
        foreach ($words as $word) {
            $word = trim($word);
            $lower = mb_strtolower($word, 'UTF-8');
            if (mb_strlen($word, 'UTF-8') < 3 || in_array($lower, $stopWords, true)) {
                continue;
            }
            $keywords[] = $word;
        }

        return $keywords;
    }

    /**
     * نرمال‌سازی URL برای مقایسه.
     */
    private function normalizeUrl(string $url): string
    {
        $url = rtrim(mb_strtolower($url, 'UTF-8'), '/');
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '/';
        $path = rtrim($path, '/');
        return ($parsed['host'] ?? '').$path;
    }
}
