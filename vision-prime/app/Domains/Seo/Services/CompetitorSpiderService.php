<?php

declare(strict_types=1);

namespace App\Domains\Seo\Services;

use App\Domains\Workspace\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * کرالر رقبا — تحلیل ساختار محتوای سایت‌های رقیب.
 *
 * برای هر رقیب:
 *   ۱) کرال صفحات (مشابه کرالر داخلی)
 *   ۲) تحلیل ساختار محتوا (عناوین، هدینگ‌ها، طول محتوا)
 *   ۳) استخراج کلمات کلیدی هدف
 *   ۴) شناسایی شکاف‌های محتوایی
 *   ۵) مقایسه با سایت خودمان
 */
class CompetitorSpiderService
{
    private const CRAWL_DELAY_MS = 500;
    private const MAX_PAGES = 30;

    /**
     * کرال یک سایت رقیب و تحلیل ساختار محتوا.
     */
    public function crawlCompetitor(string $url, string $name, int $siteId): array
    {
        $start = microtime(true);
        Log::info(self::class.' starting competitor crawl', ['url' => $url, 'name' => $name]);

        // ذخیره/آپدیت رقیب
        $competitorId = $this->upsertCompetitor($url, $name, $siteId);

        // کرال صفحات
        [$pages, $errors] = $this->crawlPages($url);

        // تحلیل ساختار
        $analysis = $this->analyzeStructure($pages, $competitorId);

        // شناسایی شکاف‌های محتوایی (مقایسه با سایت خودمان)
        $gaps = $this->detectContentGaps($analysis, $siteId, $competitorId);

        $elapsed = round(microtime(true) - $start, 1);

        Log::info(self::class.' competitor crawl completed', [
            'competitor' => $name, 'pages' => count($pages),
            'topics' => $analysis['unique_topics'], 'gaps' => count($gaps), 'duration' => $elapsed,
        ]);

        return [
            'competitor_id' => $competitorId,
            'pages_crawled' => count($pages),
            'errors' => count($errors),
            'analysis' => $analysis,
            'gaps' => $gaps,
            'duration' => $elapsed,
        ];
    }

    /**
     * ذخیره/آپدیت اطلاعات رقیب.
     */
    private function upsertCompetitor(string $url, string $name, int $siteId): int
    {
        $existing = DB::table('competitors')
            ->where('site_id', $siteId)
            ->where('url', $url)
            ->first();

        $data = [
            'site_id' => $siteId,
            'name' => $name,
            'url' => $url,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('competitors')->where('id', $existing->id)->update($data);
            return $existing->id;
        }

        return (int) DB::table('competitors')->insertGetId(array_merge($data, [
            'created_at' => now(),
        ]));
    }

    /**
     * کرال صفحات رقیب.
     */
    private function crawlPages(string $startUrl): array
    {
        $pages = [];
        $errors = [];
        $seen = [];
        $queue = [$startUrl];
        $baseHost = parse_url($startUrl, PHP_URL_HOST);

        while (count($queue) > 0 && count($pages) < self::MAX_PAGES) {
            $url = array_shift($queue);
            $normalized = $this->normalizeUrl($url);

            if (isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;

            usleep(self::CRAWL_DELAY_MS * 1000);

            try {
                $html = $this->fetchUrl($url);
                if ($html === null || $html === '') {
                    $errors[] = $url;
                    continue;
                }

                $page = $this->parsePage($html, $url);
                $pages[] = $page;

                // استخراج لینک‌های داخلی
                $links = $this->extractInternalLinks($html, $url, $baseHost);
                foreach ($links as $link) {
                    $key = $this->normalizeUrl($link);
                    if (! isset($seen[$key])) {
                        $queue[] = $link;
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = $url;
            }
        }

        return [$pages, $errors];
    }

    /**
     * دریافت HTML صفحه.
     */
    private function fetchUrl(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'VisionPrime-CompetitorBot/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode >= 200 && $httpCode < 400) ? $response : null;
    }

    /**
     * پارس HTML و استخراج اطلاعات صفحه.
     */
    private function parsePage(string $html, string $url): array
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

        // عنوان
        $titleNodes = $doc->getElementsByTagName('title');
        $title = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : '';

        // متادیتا
        $metaDesc = '';
        foreach ($doc->getElementsByTagName('meta') as $meta) {
            if ($meta->getAttribute('name') === 'description') {
                $metaDesc = $meta->getAttribute('content');
                break;
            }
        }

        // هدینگ‌ها
        $headings = ['h1' => [], 'h2' => [], 'h3' => []];
        foreach (['h1', 'h2', 'h3'] as $tag) {
            foreach ($doc->getElementsByTagName($tag) as $h) {
                $text = trim($h->textContent);
                if ($text !== '') {
                    $headings[$tag][] = $text;
                }
            }
        }

        // متن ساده
        $plainText = trim($doc->textContent ?? '');
        $wordCount = preg_match_all('/[\p{L}\p{N}]+/u', $plainText);

        // تصاویر
        $images = $doc->getElementsByTagName('image') ?: $doc->getElementsByTagName('img');
        $imgCount = $images->length;
        $imgNoAlt = 0;
        foreach ($images as $img) {
            if ($img->getAttribute('alt') === '') {
                $imgNoAlt++;
            }
        }

        // لینک‌ها
        $allLinks = $doc->getElementsByTagName('a');
        $internalLinks = 0;
        $externalLinks = 0;
        foreach ($allLinks as $a) {
            $href = $a->getAttribute('href');
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
                continue;
            }
            $host = parse_url($this->resolveUrl($href, $url), PHP_URL_HOST);
            if ($host === parse_url($url, PHP_URL_HOST)) {
                $internalLinks++;
            } else {
                $externalLinks++;
            }
        }

        // اسکیما
        $schemas = [];
        foreach ($doc->getElementsByTagName('script') as $script) {
            if ($script->getAttribute('type') === 'application/ld+json') {
                $decoded = json_decode($script->textContent, true);
                if (is_array($decoded)) {
                    $schemas[] = $decoded;
                }
            }
        }

        return [
            'url' => $url,
            'title' => $title,
            'meta_description' => $metaDesc,
            'headings' => $headings,
            'word_count' => $wordCount,
            'image_count' => $imgCount,
            'images_without_alt' => $imgNoAlt,
            'internal_links' => $internalLinks,
            'external_links' => $externalLinks,
            'schemas' => $schemas,
        ];
    }

    /**
     * تحلیل ساختار کلی محتوای رقیب.
     */
    private function analyzeStructure(array $pages, int $competitorId): array
    {
        if (empty($pages)) {
            return ['avg_word_count' => 0, 'avg_headings' => 0, 'unique_topics' => 0, 'content_types' => []];
    }

        $totalWords = 0;
        $totalHeadings = 0;
        $allH2 = [];
        $contentTypes = [];

        foreach ($pages as $page) {
            $totalWords += $page['word_count'];
            $totalHeadings += count($page['headings']['h2']);
            $allH2 = array_merge($allH2, $page['headings']['h2']);

            // تشخیص نوع محتوا
            $type = $this->detectContentType($page);
            $contentTypes[$type] = ($contentTypes[$type] ?? 0) + 1;

            // ذخیره پروفایل صفحه رقیب
            DB::table('competitor_pages')->updateOrInsert(
                ['competitor_id' => $competitorId, 'url' => $page['url']],
                [
                    'title' => $page['title'],
                    'meta_description' => $page['meta_description'],
                    'word_count' => $page['word_count'],
                    'h2_count' => count($page['headings']['h2']),
                    'content_type' => $type,
                    'internal_links' => $page['internal_links'],
                    'image_count' => $page['image_count'],
                    'has_schema' => ! empty($page['schemas']),
                    'metadata' => json_encode([
                        'h1' => $page['headings']['h1'],
                        'h2' => $page['headings']['h2'],
                        'schemas' => $page['schemas'],
                    ], JSON_UNESCAPED_UNICODE),
                    'crawled_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // استخراج موضوعات منحصربفرد
        $uniqueTopics = array_unique($allH2);
        $topicCount = count($uniqueTopics);

        // ذخیره کلمات کلیدی استخراج‌شده
        foreach ($uniqueTopics as $topic) {
            $normalized = mb_strtolower(trim($topic), 'UTF-8');
            if (mb_strlen($normalized, 'UTF-8') < 5) {
                continue;
            }
            DB::table('competitor_keywords')->updateOrInsert(
                ['competitor_id' => $competitorId, 'keyword' => $normalized],
                [
                    'occurrences' => DB::raw('occurrences + 1'),
                    'updated_at' => now(),
                ],
                ['occurrences' => 1, 'created_at' => now()]
            );
        }

        return [
            'avg_word_count' => (int) round($totalWords / max(count($pages), 1)),
            'avg_headings' => round($totalHeadings / max(count($pages), 1), 1),
            'unique_topics' => $topicCount,
            'content_types' => $contentTypes,
            'total_pages' => count($pages),
        ];
    }

    /**
     * تشخیص نوع محتوا.
     */
    private function detectContentType(array $page): string
    {
        $url = mb_strtolower($page['url'], 'UTF-8');
        $title = mb_strtolower($page['title'], 'UTF-8');

        if (str_contains($url, '/product') || str_contains($url, '/shop')) {
            return 'product';
        }
        if (str_contains($url, '/blog') || str_contains($url, '/news') || str_contains($url, '/article')) {
            return 'article';
        }
        if (preg_match('/(آموزش|راهنما|نحوه|چطور|بررسی|مقایسه|معرفی)/u', $title)) {
            return 'guide';
        }
        if ($page['word_count'] > 1000) {
            return 'longform';
        }
        return 'page';
    }

    /**
     * شناسایی شکاف‌های محتوایی — مقایسه با سایت خودمان.
     */
    private function detectContentGaps(array $analysis, int $siteId, int $competitorId): array
    {
        $gaps = [];

        // کلمات کلیدی رقیب که ما نداریم
        $competitorKeywords = DB::table('competitor_keywords')
            ->where('competitor_id', $competitorId)
            ->orderByDesc('occurrences')
            ->limit(50)
            ->get();

        $ourKeywords = DB::table('url_profiles')
            ->where('site_id', $siteId)
            ->pluck('metadata')
            ->map(fn ($m) => json_decode($m, true)['title'] ?? '')
            ->filter()
            ->map(fn ($t) => mb_strtolower($t, 'UTF-8'))
            ->toArray();

        foreach ($competitorKeywords as $ck) {
            $found = false;
            foreach ($ourKeywords as $our) {
                if (str_contains($our, $ck->keyword) || str_contains($ck->keyword, $our)) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                DB::table('static_opportunities')->updateOrInsert(
                    [
                        'site_id' => $siteId,
                        'url_profile_id' => null,
                        'type' => 'keyword_opportunity',
                        'source' => 'competitor_gap',
                        'keyword_suggested' => $ck->keyword,
                    ],
                    [
                        'title' => "شکاف محتوایی: رقیب «{$ck->keyword}» دارد، ما نداریم",
                        'score' => min(90, 50 + $ck->occurrences * 5),
                        'confidence' => 0.75,
                        'status' => 'open',
                        'explanation' => "کلمه کلیدی «{$ck->keyword}» توسط رقیب {$ck->occurrences} بار استفاده شده ولی در محتوای ما وجود ندارد",
                        'metadata' => json_encode([
                            'source' => 'competitor_analysis',
                            'competitor_id' => $competitorId,
                            'occurrences' => $ck->occurrences,
                        ], JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $gaps[] = ['keyword' => $ck->keyword, 'occurrences' => $ck->occurrences];
            }
        }

        return $gaps;
    }

    // --- Helpers ---

    private function normalizeUrl(string $url): string
    {
        $url = rtrim(mb_strtolower($url, 'UTF-8'), '/');
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '/';
        return ($parsed['host'] ?? '').rtrim($path, '/');
    }

    private function resolveUrl(string $href, string $base): string
    {
        if (str_starts_with($href, 'http')) {
            return $href;
        }
        $parsed = parse_url($base);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        if (str_starts_with($href, '//')) {
            return $scheme.':'.$href;
        }
        $basePath = $parsed['path'] ?? '/';
        $dir = substr($basePath, 0, strrpos($basePath, '/') + 1);
        return $scheme.'://'.$host.$dir.ltrim($href, '/');
    }

    private function extractInternalLinks(string $html, string $baseUrl, ?string $baseHost): array
    {
        preg_match_all('/<a\s[^>]*?href=["\']([^"\']+)["\']/iu', $html, $matches);
        $links = $matches[1] ?? [];
        $seen = [];
        $internal = [];

        foreach ($links as $link) {
            if (str_starts_with($link, 'javascript:') || str_starts_with($link, 'mailto:') || $link === '#') {
                continue;
            }
            $fullUrl = $this->resolveUrl($link, $baseUrl);
            $host = parse_url($fullUrl, PHP_URL_HOST);
            if ($host !== $baseHost) {
                continue;
            }
            $key = $this->normalizeUrl($fullUrl);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $internal[] = $fullUrl;
            }
        }

        return $internal;
    }
}
