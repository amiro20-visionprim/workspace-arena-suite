<?php

declare(strict_types=1);

namespace App\Domains\Seo\Services;

use App\Domains\Workspace\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

/**
 * راهکار ۲ — داخلی کرالر (بدون نیاز به GSC یا اینترنت خارجی).
 *
 * هر شب:
 *   ۱) crawl all public pages of the site
 *   ۲) استخراج structure, meta, images, links, schema
 *   ۳) observation content profiling (type, subtype, word count, heading ratios)
 *   ۴) observation quality gaps (thin content, mixed content, missing alt, broken link)
 *   ۵) intra-site topological analysis (orphan pages, interlink gaps)
 *   ۶) generate static opportunities & risks
 *   ۷) upsert url_profiles (server-side, without gsc data)
 *
 * شرایط محلی:
 *   curl extension → curl self -> http://liuna.ir/... (هیچ نیاز به خارجی نیست)
 *   proxy設定: config('services.crawler.proxy') (اختیاری)
 */
class SiteSpiderService
{
    /** حداکثر صفحه‌ای در هر اجرا crawl می‌شود */
    private const MAX_PAGES_PER_RUN = 50;

    /** تاخیر بین کوئری‌های crawl (robots-delay) */
    private const CRAWL_DELAY_MS = 200;

    /** آستانه similarity برای keyword opportunity از عنوان‌ها */
    private const SIMILARITY_THRESHOLD = 0.4;

    /** حداقل تعداد واژه برای thin content detection */
    private const THIN_CONTENT_WORDS = 300;

    public function __construct()
    {
    }

    /**
     * اجرای اصلی کرالر — فراخوانی توسط Job nightly.
     */
    public function crawl(Site $site): array
    {
        $startUrl = rtrim((string) $site->canonical_url, '/').'/';
        Log::info(self::class.' starting crawl', ['site_id'=>$site->id,'start_url'=>$startUrl]);

        [$urls, $crawlErrors] = $this->buildCrawlQueue($site, $startUrl);

        if ($urls === []) {
            Log::warning(self::class.' no pages to crawl', ['site_id'=>$site->id]);

            return [
                'pages_crawled' => 0, 'profiles_created' => 0, 'profiles_updated' => 0,
                'opportunities_created' => 0, 'risks_detected' => 0, 'crawl_errors' => count($crawlErrors),
            ];
        }

        $profilesCreated = 0;
        $profilesUpdated = 0;
        $opportunitiesCreated = 0;
        $risksDetected = 0;

        foreach ($urls as $url) {
            usleep(self::CRAWL_DELAY_MS * 1000);

            try {
                $page = $this->fetchAndParse($url, $site);
                if ($page === null) {
                    $crawlErrors[] = $url;
                    continue;
                }

                $profileId = $this->upsertUrlProfile($page, $site);
                if ($profileId === null) {
                    $profilesUpdated++;
                } else {
                    $profilesCreated++;
                }

                // فرصت‌یابی و ریسک برای همه صفحات (جدید و موجود)
                $gaps = $this->detectQualityGaps($page, $site);
                $risksDetected += count($gaps);

                $opps = $this->detectStaticOpportunities($page, $site);
                $opportunitiesCreated += count($opps);
            } catch (\Throwable $e) {
                Log::error(self::class.'.crawl.error', [
                    'site_id' => $site->id, 'url' => $url, 'error' => $e->getMessage(),
                ]);
                $crawlErrors[] = $url;
            }
        }

        Log::info(self::class.' crawl completed', ['site_id' => $site->id, 'pages' => count($urls)]);

        return [
            'pages_crawled' => count($urls),
            'profiles_created' => $profilesCreated,
            'profiles_updated' => $profilesUpdated,
            'opportunities_created' => $opportunitiesCreated,
            'risks_detected' => $risksDetected,
            'crawl_errors' => count($crawlErrors),
        ];
    }

    /**
     * ساخت_bfs هر public pages.
     * شروع از canonical_url، crawl all internal links.
     */
    private function buildCrawlQueue(Site $site, string $startUrl): array
    {
        $seen = [];
        $queue = [$startUrl];
        $baseHost = parse_url($startUrl, PHP_URL_HOST);

        $crawlErrors = [];
        $maxPages = self::MAX_PAGES_PER_RUN;
        $visitedCount = 0;

        while (count($queue) > 0 && $visitedCount < $maxPages) {
            $url = array_shift($queue);
            $urlKey = $this->normalizeUrl($url);

            if (isset($seen[$urlKey]) || $visitedCount >= $maxPages) {
                continue;
            }
            $seen[$urlKey] = true;
            $visitedCount++;

            try {
                $html = $this->fetchUrl($url, $site);
                if ($html === null || $html === '') {
                    $crawlErrors[] = $url.' (empty response)';
                    continue;
                }

                $links = $this->extractInternalLinks($html, $url, $baseHost);

                foreach ($links as $link) {
                    $linkKey = $this->normalizeUrl($link);
                    if (! isset($seen[$linkKey])) {
                        $queue[] = $link;
                    }
                }
            } catch (\Throwable $e) {
                $crawlErrors[] = $url.' ('.$e->getMessage().')';
            }
        }

        return [array_keys($seen), $crawlErrors];
    }

    /**
     * برداشت HTML صفحه (curl).
     */
    private function fetchUrl(string $url, Site $site): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'VisionPrime-Crawler/1.0 (+https://visionprime-suite.ir)',
        ]);

        $proxy = config('services.crawler.proxy');
        if ($proxy !== null && $proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 400) {
            return null;
        }

        if ($error !== '') {
            return null;
        }

        return $response;
    }

    private function extractInternalLinks(string $html, string $baseUrl, ?string $baseHost): array
    {
        $pattern = '/<a\s[^>]*?href=["\']([^"\']+)["\']/iu';
        preg_match_all($pattern, $html, $matches);
        $links = $matches[1] ?? [];

        $seen = [];
        $internal = [];

        $basePath = parse_url($baseUrl, PHP_URL_PATH) ?? '/';
        $basePath = rtrim($basePath, '/');

        foreach ($links as $link) {
            if (str_starts_with($link, 'javascript:') ||
                str_starts_with($link, 'mailto:') ||
                str_starts_with($link, 'tel:') ||
                $link === '#') {
                continue;
            }

            $fullUrl = $this->resolveUrl($link, $baseUrl);
            $host = parse_url($fullUrl, PHP_URL_HOST);

            if ($host !== $baseHost) {
                continue;
            }

            $path = parse_url($fullUrl, PHP_URL_PATH) ?? '/';
            $lower = mb_strtolower($path);

            // فیلتر غیرمحتوایی
            $skipPaths = ['wp-admin','wp-login','wp-json','xmlrpc','feed','sitemap','author','tag','comment','search','page','comments','trackback'];
            $skip = false;
            foreach ($skipPaths as $skipPath) {
                if (str_contains($lower, '/'.$skipPath.'/') || str_contains($lower, '/'.$skipPath)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            $linkKey = $this->normalizeUrl($fullUrl);
            if (isset($seen[$linkKey])) {
                continue;
            }
            $seen[$linkKey] = true;
            $internal[] = $fullUrl;
        }

        return array_values($internal);
    }

    private function resolveUrl(string $relativeOrAbsolute, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $relativeOrAbsolute)) {
            return $relativeOrAbsolute;
        }

        if ($relativeOrAbsolute[0] === '/') {
            $host = parse_url($baseUrl, PHP_URL_SCHEME).'://'.parse_url($baseUrl, PHP_URL_HOST);
            return $host.$relativeOrAbsolute;
        }

        $basePath = parse_url($baseUrl, PHP_URL_PATH) ?? '/';
        $basePath = dirname($basePath);

        return rtrim($basePath, '/').'/'.ltrim($relativeOrAbsolute, '/');
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? '/';
        $query = $parts['query'] ?? '';

        // remove trailing slash
        $path = $path === '/' || $path === '' ? '/' : rtrim($path, '/');

        return ($parts['scheme'] ?? 'http').'://'.
            ($parts['host'] ?? '').$path.
            ($query ? '?'.$query : '');
    }

    private function fetchAndParse(string $url, Site $site): ?array
    {
        $html = $this->fetchUrl($url, $site);
        if ($html === null || $html === '') {
            return null;
        }

        return $this->parsePage($html, $url);
    }

    private function parsePage(string $html, string $url): array
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

        $titleEl = $doc->getElementsByTagName('title')->item(0);
        $title = $titleEl ? trim($titleEl->textContent) : '';

        $metaDescription = '';
        $metaRobots = '';
        $metaCanonical = '';
        $metaOgTitle = '';
        $metaOgDescription = '';
        $metaOgImage = '';

        foreach ($doc->getElementsByTagName('meta') as $meta) {
            $name = $meta->getAttribute('name') ?: $meta->getAttribute('property');
            $content = $meta->getAttribute('content');
            if ($name === 'description') $metaDescription = trim($content);
            if ($name === 'robots') $metaRobots = trim($content);
            if ($name === 'canonical' || $name === 'og:url') $metaCanonical = trim($content);
            if ($name === 'og:title') $metaOgTitle = trim($content);
            if ($name === 'og:description') $metaOgDescription = trim($content);
            if ($name === 'og:image') $metaOgImage = trim($content);
        }

        $headings = [];
        foreach ($doc->getElementsByTagName('h1') as $h1) $headings['h1'][] = trim($h1->textContent);
        foreach ($doc->getElementsByTagName('h2') as $h2) $headings['h2'][] = trim($h2->textContent);
        foreach ($doc->getElementsByTagName('h3') as $h3) $headings['h3'][] = trim($h3->textContent);

        $paragraphs = [];
        foreach ($doc->getElementsByTagName('p') as $p) $paragraphs[] = trim($p->textContent);

        $plainText = strip_tags($html);
        $words = preg_split('/\s+/u', $plainText, -1, PREG_SPLIT_NO_EMPTY);
        $wordCount = count($words);

        $images = [];
        foreach ($doc->getElementsByTagName('img') as $img) {
            $images[] = [
                'src' => $img->getAttribute('src'),
                'alt' => $img->getAttribute('alt'),
                'width' => (int) $img->getAttribute('width'),
                'height' => (int) $img->getAttribute('height'),
            ];
        }
        $imagesWithoutAlt = count(array_filter($images, fn(array $img): bool => $img['alt'] === ''));

        $internalLinks = 0;
        $externalLinks = 0;
        $linkTargets = [];

        $siteHost = parse_url($url, PHP_URL_HOST);
        foreach ($doc->getElementsByTagName('a') as $a) {
            $href = $a->getAttribute('href');
            if ($href === '') continue;
            $parsed = parse_url($href);
            if (isset($parsed['host']) && $parsed['host'] !== $siteHost) {
                $externalLinks++;
            } else {
                $internalLinks++;
                $linkTargets[] = trim($a->textContent);
            }
        }

        $schemas = [];
        foreach ($doc->getElementsByTagName('script') as $script) {
            if ($script->getAttribute('type') !== 'application/ld+json') continue;
            $text = $script->textContent;
            $decoded = json_decode($text, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $schemas[] = $decoded;
            }
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        $contentType = $this->detectContentType($path, $title, $headings);
        $subtype = $this->detectSubtype($title, $headings, $metaDescription);

        return [
            'url' => $url,
            'canonical_url' => $metaCanonical ?: $url,
            'title' => $title,
            'meta_title' => $metaOgTitle ?: $title,
            'meta_description' => $metaDescription ?: $metaOgDescription,
            'content_type' => $contentType,
            'subtype' => $subtype,
            'h1' => $headings['h1'] ?? [],
            'h2' => $headings['h2'] ?? [],
            'h3' => $headings['h3'] ?? [],
            'paragraphs' => $paragraphs,
            'word_count' => $wordCount,
            'character_count' => mb_strlen($plainText, 'UTF-8'),
            'image_count' => count($images),
            'images_without_alt' => $imagesWithoutAlt,
            'images' => $images,
            'internal_links' => $internalLinks,
            'external_links' => $externalLinks,
            'link_targets' => $linkTargets,
            'schemas' => $schemas,
            'meta_robots' => $metaRobots,
            'crawled_at' => now(),
        ];
    }

    private function detectContentType(string $path, string $title, array $headings): string
    {
        $lowerPath = mb_strtolower($path);

        if (str_contains($lowerPath, '/product') ||
            str_contains($lowerPath, '/shop') ||
            str_contains($lowerPath, '/category') ||
            str_contains($lowerPath, '/محصول')) {
            return 'product';
        }

        if (str_contains($lowerPath, '/blog') ||
            str_contains($lowerPath, '/news') ||
            str_contains($lowerPath, '/magazine') ||
            str_contains($lowerPath, '/articles') ||
            str_contains($lowerPath, '/مقالات') ||
            str_contains($lowerPath, '/پست')) {
            return 'post';
        }

        if (count($headings['h1'] ?? []) > 0 &&
            count($headings['h2'] ?? []) > 1 &&
            ($this->wordCount ?? 0) > 300) {
            return 'article';
        }

        if (preg_match('/(آموزش|راهنما|نحوه|چطور|بررسی|مقایسه|معرفی)/iu', $title)) {
            return 'article';
        }

        return 'page';
    }

    private function detectSubtype(string $title, array $headings, string $metaDescription): string
    {
        $c = mb_strtolower($title).' '.
            implode(' ', array_merge($headings['h1'] ?? [], $headings['h2'] ?? []));

        if (preg_match('/(آموزش|نحوه|چطور|راهنما|گام به گام|دستورالعمل)/iu', $c)) {
            return 'tutorial';
        }
        if (preg_match('/(مقایسه|تفاوت|یا|کدام|بهتر|برتری)/iu', $c)) {
            return 'comparison';
        }
        if (preg_match('/(بررسی|نقد|تجربه|مطالعه|بدون|پیشنهاد|معرفی)/iu', $c)) {
            return 'review';
        }
        if (preg_match('/(۱۰|۵|۲۰|بالاترین|بهترین|برترین|لیست|نکات|ایده)/iu', $c)) {
            return 'listicle';
        }
        if (preg_match('/(اخبار|آخرین|جدید|آیین|اعلام|نظرسنجی)/iu', $c)) {
            return 'news';
        }

        if (count($headings['h2'] ?? []) >= 3) {
            return 'article';
        }

        return 'general';
    }

    private function upsertUrlProfile(array $page, Site $site): ?int
    {
        $canonicalUrl = $page['canonical_url'];
        $existing = DB::table('url_profiles')
            ->where('site_id', $site->id)
            ->where('canonical_url', $canonicalUrl)
            ->first();

        $metadata = json_encode([
            'title' => $page['title'],
            'meta_title' => $page['meta_title'],
            'meta_description' => $page['meta_description'],
            'headings' => [
                'h1' => $page['h1'],
                'h2' => $page['h2'],
                'h3' => $page['h3'],
            ],
            'paragraphs' => $page['paragraphs'],
            'word_count' => $page['word_count'],
            'character_count' => $page['character_count'],
            'image_count' => $page['image_count'],
            'images_without_alt' => $page['images_without_alt'],
            'internal_links' => $page['internal_links'],
            'external_links' => $page['external_links'],
            'link_targets' => $page['link_targets'],
            'schemas' => $page['schemas'],
            'meta_robots' => $page['meta_robots'],
            'crawled_at' => (string) $page['crawled_at'],
        ], JSON_UNESCAPED_UNICODE);

        $payload = [
            'site_id' => $site->id,
            'public_id' => Str::ulid(),
            'canonical_url' => $canonicalUrl,
            'content_type' => $page['content_type'],
            'post_status' => 'publish',
            'metadata' => $metadata,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('url_profiles')->where('id', $existing->id)->update($payload);
            return null;
        }

        $id = DB::table('url_profiles')->insertGetId(array_merge($payload, [
            'created_at' => now(),
        ]));

        return $id;
    }

    private function detectQualityGaps(array $page, Site $site): array
    {
        $risks = [];
        $siteId = $site->id;
        $urlId = DB::table('url_profiles')
            ->where('site_id', $siteId)
            ->where('canonical_url', $page['canonical_url'])
            ->value('id');

        if ($urlId === null) {
            return $risks;
        }

        if ($page['word_count'] < self::THIN_CONTENT_WORDS) {
            DB::table('static_risk_patterns')->updateOrInsert(
                [
                    'site_id' => $siteId,
                    'url_profile_id' => $urlId,
                    'key' => 'thin_content',
                ],
                [
                    'severity' => 'medium',
                    'status' => 'open',
                    'explanation' => sprintf(
                        'صفحه %s محتوای ضعیف (کمتر از %d کلمه) دارد — بهبود محتوا توصیه می‌شود',
                        $page['canonical_url'],
                        self::THIN_CONTENT_WORDS
                    ),
                    'metadata' => json_encode(['word_count' => $page['word_count'], 'min' => self::THIN_CONTENT_WORDS], JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $risks[] = 'thin_content';
        }

        if ($page['meta_description'] === '') {
            DB::table('static_risk_patterns')->updateOrInsert(
                [
                    'site_id' => $siteId,
                    'url_profile_id' => $urlId,
                    'key' => 'missing_meta_description',
                ],
                [
                    'severity' => 'medium',
                    'status' => 'open',
                    'explanation' => sprintf('برای آدرس %s، توضیح متا (meta description) تعریف نشده است', $page['canonical_url']),
                    'metadata' => json_encode(['url' => $page['canonical_url']], JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $risks[] = 'missing_meta_description';
        }

        if ($page['images_without_alt'] > 0) {
            DB::table('static_risk_patterns')->updateOrInsert(
                [
                    'site_id' => $siteId,
                    'url_profile_id' => $urlId,
                    'key' => 'images_without_alt',
                ],
                [
                    'severity' => 'low',
                    'status' => 'open',
                    'explanation' => sprintf(
                        'در صفحه %s، %d عکس بدون متن جایگزین (alt) وجود دارد — بهبود دسترسی‌پذیری',
                        $page['canonical_url'], $page['images_without_alt']
                    ),
                    'metadata' => json_encode(['count' => $page['images_without_alt'], 'url' => $page['canonical_url']], JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $risks[] = 'images_without_alt';
        }

        $h1Count = count($page['h1'] ?? []);
        if ($h1Count > 1) {
            DB::table('static_risk_patterns')->updateOrInsert(
                [
                    'site_id' => $siteId,
                    'url_profile_id' => $urlId,
                    'key' => 'multiple_h1',
                ],
                [
                    'severity' => 'medium',
                    'status' => 'open',
                    'explanation' => sprintf('صفحه %s دارای %d هدینگ H1 است — بهتر است فقط یک H1 باشد', $page['canonical_url'], $h1Count),
                    'metadata' => json_encode(['h1_count' => $h1Count, 'url' => $page['canonical_url']], JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $risks[] = 'multiple_h1';
        }

        return $risks;
    }

    private function detectStaticOpportunities(array $page, Site $site): array
    {
        $opportunities = [];
        $siteId = $site->id;
        $urlId = DB::table('url_profiles')
            ->where('site_id', $siteId)
            ->where('canonical_url', $page['canonical_url'])
            ->value('id');

        if ($urlId === null) {
            return $opportunities;
        }

        // ۱) فرصت‌های کلیدواژه بر اساس H2
        $h2List = $page['h2'] ?? [];
        foreach ($h2List as $h2) {
            $h2Norm = trim($h2);
            if ($h2Norm === '' || mb_strlen($h2Norm, 'UTF-8') < 8) continue;

            DB::table('static_opportunities')->updateOrInsert(
                [
                    'site_id' => $siteId,
                    'url_profile_id' => $urlId,
                    'type' => 'keyword_opportunity',
                    'source' => 'crawler_h2_pattern',
                    'keyword_suggested' => $h2Norm,
                ],
                [
                    'title' => "پیشنهاد کلیدواژه: {$h2Norm}",
                    'keyword_suggested' => $h2Norm,
                    'score' => 65,
                    'confidence' => 0.65,
                    'status' => 'open',
                    'explanation' => sprintf('هدینگ H2 «%s» پتانسیل کلیدواژه هدف دارد', $h2Norm),
                    'metadata' => json_encode(['source' => 'crawler', 'h2' => $h2Norm, 'url' => $page['canonical_url']], JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $opportunities[] = ['type' => 'keyword_opportunity', 'keyword_suggested' => $h2Norm, 'score' => 65];
        }

        // ۲) محتوای کم‌عمق (زیر ۳۰۰ کلمه)
        $wc = $page['word_count'] ?? 0;
        if ($wc > 0 && $wc < 300) {
            DB::table('static_opportunities')->updateOrInsert(
                [
                    'site_id' => $siteId, 'url_profile_id' => $urlId,
                    'type' => 'content_gap', 'source' => 'crawler_thin_content',
                ],
                [
                    'title' => 'محتوای کم‌عمق — نیاز به تقویت',
                    'keyword_suggested' => $page['title'] ?? '',
                    'score' => 80,
                    'confidence' => 0.85,
                    'status' => 'open',
                    'explanation' => sprintf('این صفحه فقط %d کلمه دارد — زیر آستانه ۳۰۰ کلمه', $wc),
                    'metadata' => json_encode(['source' => 'crawler', 'word_count' => $wc, 'url' => $page['canonical_url']], JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(), 'created_at' => now(),
                ]
            );
            $opportunities[] = ['type' => 'content_gap', 'score' => 80];
        }

        // ۳) متادیسکریپشن خالی
        if (empty($page['meta_description'])) {
            DB::table('static_opportunities')->updateOrInsert(
                [
                    'site_id' => $siteId, 'url_profile_id' => $urlId,
                    'type' => 'meta_optimization', 'source' => 'crawler_missing_meta',
                ],
                [
                    'title' => 'متادیسکریپشن خالی — بهینه‌سازی CTR',
                    'keyword_suggested' => $page['title'] ?? '',
                    'score' => 75,
                    'confidence' => 0.9,
                    'status' => 'open',
                    'explanation' => 'متادیسکریپشن این صفحه خالی است — اضافه کردن آن CTR را بهبود می‌دهد',
                    'metadata' => json_encode(['source' => 'crawler', 'url' => $page['canonical_url']], JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(), 'created_at' => now(),
                ]
            );
            $opportunities[] = ['type' => 'meta_optimization', 'score' => 75];
        }

        // ۴) اسکیمای JSON-LD ناقص
        $schemas = $page['schemas'] ?? [];
        if (empty($schemas)) {
            DB::table('static_opportunities')->updateOrInsert(
                [
                    'site_id' => $siteId, 'url_profile_id' => $urlId,
                    'type' => 'schema_gap', 'source' => 'crawler_missing_schema',
                ],
                [
                    'title' => 'اسکیمای Schema.org ندارد',
                    'keyword_suggested' => '',
                    'score' => 70,
                    'confidence' => 0.8,
                    'status' => 'open',
                    'explanation' => 'این صفحه هیچ اسکیمای JSON-LD ندارد — اضافه کردن آن Rich Snippet ایجاد می‌کند',
                    'metadata' => json_encode(['source' => 'crawler', 'url' => $page['canonical_url']], JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(), 'created_at' => now(),
                ]
            );
            $opportunities[] = ['type' => 'schema_gap', 'score' => 70];
        }

        // ۵) تصاویر بدون Alt
        $imgNoAlt = $page['images_without_alt'] ?? 0;
        if ($imgNoAlt > 0) {
            DB::table('static_opportunities')->updateOrInsert(
                [
                    'site_id' => $siteId, 'url_profile_id' => $urlId,
                    'type' => 'accessibility', 'source' => 'crawler_images_no_alt',
                ],
                [
                    'title' => "{$imgNoAlt} تصویر بدون متن Alt",
                    'keyword_suggested' => '',
                    'score' => 55,
                    'confidence' => 0.95,
                    'status' => 'open',
                    'explanation' => sprintf('%d تصویر بدون متن Alt — بهبود دسترسی و سئوی تصاویر', $imgNoAlt),
                    'metadata' => json_encode(['count' => $imgNoAlt, 'url' => $page['canonical_url']], JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(), 'created_at' => now(),
                ]
            );
            $opportunities[] = ['type' => 'accessibility', 'score' => 55];
        }

        return $opportunities;
    }

    public function syncStaticOpportunities(Site $site): int
    {
        return DB::table('static_opportunities')
            ->where('site_id', $site->id)
            ->where('status', 'open')
            ->count();
    }

    public function crawlStaticSite(Site $site): array
    {
        return $this->crawl($site);
    }
}
