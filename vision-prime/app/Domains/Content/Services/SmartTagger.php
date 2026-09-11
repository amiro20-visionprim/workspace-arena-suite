<?php

declare(strict_types=1);

namespace App\Domains\Content\Services;

class SmartTagger
{
    private const STOP_WORDS = [
        'و', 'در', 'به', 'از', 'که', 'این', 'را', 'با', 'است', 'برای',
        'آن', 'یک', 'خود', 'تا', 'کرد', 'بر', 'هم', 'بود', 'سوی', 'یا',
        'اما', 'پس', 'نه', 'اگر', 'همه', 'بی', 'بیشتر', 'کمتر', 'خیلی',
        'هر', 'آنچه', 'دیگر', 'چه', 'چرا', 'چگونه', 'کجا', 'کی', 'چیست',
        'هست', 'شود', 'شد', 'شده', 'می', 'ها', 'های', 'هایی', 'ای',
        'ام', 'ات', 'اش', 'مان', 'تان', 'شان', 'ترین', 'تر', 'گر',
        'جدید', 'بهترین', 'مختلف', 'مهم', 'خاص', 'اصلی', 'ساده', 'کامل',
        'عالی', 'رایگان', 'درباره', 'بعد', 'قبل', 'هنگام', 'زمان', 'امروز',
    ];

    private const TOPIC_MAP = [
        'آرایش' => ['آرایش صورت', 'آرایش چشم', 'آرایش لب', 'آرایش ابرو', 'لوازم آرایشی', 'بیوتی بلندر'],
        'پوست' => ['مراقبت از پوست', 'پوست چرب', 'پوست خشک', 'پوست حساس', 'آکنه', 'جوان‌سازی پوست'],
        'مو' => ['مراقبت از مو', 'رنگ مو', 'شامپو', 'ماسک مو'],
        'بهداشت' => ['بهداشت شخصی', 'بهداشت پوست', 'بهداشت مو'],
        'زیبایی' => ['زیبایی طبیعی', 'سبک زندگی', 'سلامت'],
        'تابستان' => ['آفتاب', 'ضد آفتاب', 'پوست چرب', 'آرایش تابستانی'],
        'خلیج' => ['آرایش خلیجی', 'آرایش عربی', 'آرایش مجلسی'],
        'آشپزی' => ['آشپزی ایرانی', 'غذا', 'دستور پخت', 'خوراکی'],
        'سفر' => ['گردشگری', 'طبیعت', 'هتل', 'چمدان'],
        'ورزش' => ['بدنسازی', 'یوگا', 'ورزش خانگی'],
        'ماشین' => ['خودرو', 'ماشین ایرانی', 'قیمت ماشین'],
        'گجت' => ['تکنولوژی', 'اسمارت واچ', 'هدفون', 'گوشی'],
    ];

    public static function suggest(
        string $title,
        string $content = '',
        string $focusKeyword = '',
        array $existingTags = []
    ): array {
        $title = trim($title);
        $content = strip_tags($content);

        $candidates = [];

        // 1) Focus keyword first (highest priority)
        if ($focusKeyword !== '') {
            $candidates[] = $focusKeyword;
        }

        // 2) Meaningful consecutive phrases from the title (2-3 words)
        foreach (self::extractTitlePhrases($title) as $phrase) {
            $candidates[] = $phrase;
        }

        // 3) Meaningful single words from the title
        foreach (self::extractMeaningfulWords($title) as $word) {
            $candidates[] = $word;
        }

        // 4) High-frequency words from the content (not in title)
        foreach (self::extractContentWords($title, $content) as $word) {
            $candidates[] = $word;
        }

        // 5) Topic-based tags
        $topicTags = self::findTopicTags($title . ' ' . $content);

        // Score + dedupe (keep highest score per exact name)
        $scored = [];
        foreach ($candidates as $cand) {
            $cand = trim($cand);
            if ($cand === '' || mb_strlen($cand) < 3) {
                continue;
            }
            $score = self::calculateTagScore($cand, $title, $content);
            $key = mb_strtolower($cand);
            if (!isset($scored[$key]) || $score > $scored[$key]['score']) {
                $scored[$key] = [
                    'name' => $cand,
                    'score' => $score,
                    'reason' => self::getTagReason($cand, $title),
                ];
            }
        }
        foreach ($topicTags as $tag) {
            $key = mb_strtolower($tag['name']);
            if (!isset($scored[$key])) {
                $scored[$key] = $tag;
            }
        }

        $allTags = array_values($scored);

        // Score floor: drop weak generic terms that only come from content
        // frequency without any title grounding. These are noise when the source
        // content is thin (e.g. a brand name with little surrounding text).
        $allTags = array_values(array_filter($allTags, function ($tag) use ($title) {
            if ($tag['score'] >= 75) {
                return true;
            }
            // Below 75 only survives if it appears in the title (real signal)
            // or is a meaningful multi-word phrase (2+ words).
            $inTitle = str_contains($title, $tag['name']);
            $wordCount = count(preg_split('/\s+/', $tag['name']));
            return $inTitle || $wordCount >= 2;
        }));

        $allTags = self::matchExistingTags($allTags, $existingTags);

        usort($allTags, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($allTags, 0, 10);
    }

    /**
     * Extract consecutive meaningful phrases (2-3 words) from the title.
     * A phrase is kept only if NONE of its words is a stop word, so
     * junk like "گوشی های جدید" is never produced.
     */
    private static function extractTitlePhrases(string $title): array
    {
        $parts = preg_split('/\s+/', trim(self::normalizePersian($title)));
        $parts = array_values(array_filter($parts, fn($w) => $w !== ''));

        $phrases = [];
        $n = count($parts);

        // Bigrams of consecutive meaningful words
        for ($i = 0; $i < $n - 1; $i++) {
            $pair = [$parts[$i], $parts[$i + 1]];
            if (self::isMeaningfulPhrase($pair)) {
                $phrases[] = implode(' ', $pair);
            }
        }

        // Trigrams of consecutive meaningful words
        for ($i = 0; $i < $n - 2; $i++) {
            $triple = [$parts[$i], $parts[$i + 1], $parts[$i + 2]];
            if (self::isMeaningfulPhrase($triple)) {
                $phrases[] = implode(' ', $triple);
            }
        }

        // Dedupe, keep order
        $unique = [];
        foreach ($phrases as $p) {
            $unique[mb_strtolower($p)] = $p;
        }
        return array_values($unique);
    }

    private static function isMeaningfulPhrase(array $words): bool
    {
        foreach ($words as $w) {
            if (in_array($w, self::STOP_WORDS, true) || mb_strlen($w) < 3) {
                return false;
            }
        }
        return true;
    }

    private static function extractMeaningfulWords(string $title): array
    {
        $words = preg_split('/\s+/', trim(self::normalizePersian($title)));
        $out = [];
        foreach ($words as $w) {
            if (mb_strlen($w) >= 3 && !in_array($w, self::STOP_WORDS, true)) {
                $out[$w] = $w;
            }
        }
        return array_values($out);
    }

    private static function extractContentWords(string $title, string $content): array
    {
        $words = preg_split('/\s+/', self::normalizePersian($content));
        $titleWords = self::extractMeaningfulWords($title);
        $counts = [];

        foreach ($words as $w) {
            $w = trim($w);
            if (mb_strlen($w) < 3 || in_array($w, self::STOP_WORDS, true)) {
                continue;
            }
            $counts[$w] = ($counts[$w] ?? 0) + 1;
        }

        // Only words appearing 2+ times and not already covered by the title
        $out = [];
        foreach ($counts as $w => $c) {
            if ($c >= 2 && !in_array($w, $titleWords, true)) {
                $out[] = $w;
            }
        }
        return $out;
    }

    private static function normalizePersian(string $text): string
    {
        $text = preg_replace('/\x{200C}/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/[\x{00AB}\x{00BB}\x{201C}\x{201D}\x{2018}\x{2019}\x{003F}\x{0021}\x{002E}\x{002C}\x{003B}\x{003A}\x{0028}\x{0029}\x{005B}\x{005D}\x{007B}\x{007D}\x{002D}\x{002F}]/u', ' ', $text);
        return trim($text);
    }

    private static function findTopicTags(string $text): array
    {
        $tags = [];
        foreach (self::TOPIC_MAP as $topic => $relatedTags) {
            if (!self::containsWord($text, $topic)) {
                continue;
            }
            foreach ($relatedTags as $tag) {
                $tags[] = [
                    'name' => $tag,
                    'score' => 70,
                    'reason' => "مرتبط با موضوع {$topic}",
                ];
            }
        }
        return $tags;
    }

    /**
     * Word-boundary aware containment: the topic word must appear as a
     * standalone word, not as a substring inside an unrelated word.
     */
    private static function containsWord(string $text, string $word): bool
    {
        $pattern = '/(?<![\\p{L}\\p{N}])' . preg_quote($word, '/') . '(?![\\p{L}\\p{N}])/u';
        return (bool) preg_match($pattern, $text);
    }

    private static function calculateTagScore(string $tag, string $title, string $content): int
    {
        $score = 50;
        if (str_contains($title, $tag)) $score += 20;
        if (str_contains($content, $tag)) $score += 10;
        $wordCount = count(preg_split('/\s+/', $tag));
        if ($wordCount >= 2 && $wordCount <= 3) $score += 10;
        if (str_contains($title, $tag) && $wordCount >= 2) $score += 15;
        return min(100, $score);
    }

    private static function getTagReason(string $tag, string $title): string
    {
        if (str_contains($title, $tag)) return 'کلمه کلیدی عنوان';
        return 'کلمه پرتکرار در محتوا';
    }

    private static function matchExistingTags(array $suggested, array $existingTags): array
    {
        if (empty($existingTags)) return $suggested;

        foreach ($suggested as &$tag) {
            foreach ($existingTags as $wpTag) {
                $wpName = is_array($wpTag) ? ($wpTag['name'] ?? '') : ($wpTag->name ?? '');
                if (mb_strtolower($wpName) === mb_strtolower($tag['name'])) {
                    $tag['existing'] = true;
                    $tag['wp_id'] = is_array($wpTag) ? ($wpTag['id'] ?? 0) : ($wpTag->term_id ?? 0);
                    $tag['score'] = min(100, $tag['score'] + 10);
                    break;
                }
                if (str_contains(mb_strtolower($wpName), mb_strtolower($tag['name']))
                    || str_contains(mb_strtolower($tag['name']), mb_strtolower($wpName))) {
                    $tag['similar_to'] = $wpName;
                    $tag['score'] = min(100, $tag['score'] + 5);
                    break;
                }
            }
        }
        unset($tag);

        return $suggested;
    }
}