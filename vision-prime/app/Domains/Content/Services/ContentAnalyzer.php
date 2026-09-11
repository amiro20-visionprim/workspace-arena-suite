<?php

declare(strict_types=1);

namespace App\Domains\Content\Services;

/**
 * تحلیل‌گر محتوا — پیشنهاد هوشمند focus_keyword
 *
 * بر اساس عنوان، نوع محتوا، و زیرنوع، کلمه کلیدی بهینه پیشنهاد می‌دهد.
 * از الگوریتم‌های زیر استفاده می‌کند:
 * 1. تحلیل ساختار عنوان (تشخیص الگوهای رایج)
 * 2. استخراج واژه‌های کلیدی از عنوان
 * 3. پیشنهاد بر اساس نوع محتوا
 * 4. محاسبه امتیاز رقابتی
 */
class ContentAnalyzer
{
    /**
     * الگوهای رایج عنوان فارسی
     */
    private const TITLE_PATTERNS = [
        // آموزشی
        'tutorial' => [
            'patterns' => ['آموزش', 'نحوه', 'چطور', 'راهنمای', 'یاد بگیرید', 'گام به گام'],
            'weight' => 1.2,
        ],
        // مقایسه‌ای
        'comparison' => [
            'patterns' => ['مقایسه', 'تفاوت', '对比', 'vs', 'یا', 'کدام بهتر'],
            'weight' => 1.1,
        ],
        // بررسی
        'review' => [
            'patterns' => ['بررسی', 'نقد', 'تجربه', 'معرفی', 'بهترین'],
            'weight' => 1.15,
        ],
        // لیستی
        'listicle' => [
            'patterns' => ['۱۰ تا', '۵ تا', 'برترین', 'لیست', 'ترفندهای', 'ایده‌ها'],
            'weight' => 1.1,
        ],
        // خبری
        'news' => [
            'patterns' => ['خبر', 'اخبار', 'آخرین', 'جدید', 'علنی شد', 'رونمایی'],
            'weight' => 0.9,
        ],
    ];

    /**
     * واژه‌های اضافی (stop words) که نباید در کلمه کلیدی باشن
     */
    private const STOP_WORDS = [
        'و', 'در', 'به', 'از', 'با', 'برای', 'که', 'این', 'آن', 'را',
        'می', 'شد', 'است', 'بود', 'شود', 'می‌شود', 'هست', 'بودن',
        'شدن', 'کردن', 'داشتن', 'باید', 'می‌توان', 'می‌تواند',
        'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه', 'ده',
        'درباره', 'طریق', 'روش', 'نحوه', 'چگونه', 'چطور',
    ];

    /**
     * کلمات جذاب برای SEO
     */
    private const ATTRACTIVE_WORDS = [
        'بهترین', 'برترین', 'مقایسه', 'بررسی',
        'آموزش', 'راهنما', 'ترفند', 'گامبه گام',
    ];

    /**
     * الگوهای فصلی
     */
    private const SEASONAL_PATTERNS = [
        'تابستانی' => 'تابستانی',
        'زمستانی' => 'زمستانی',
        'بهار' => 'بهاری',
        'پاییز' => 'پاییزی',
    ];

    /**
     * پیشنهاد هوشمند focus_keyword
     *
     * @param  array{title: string, content_type?: string, subtype?: string, target_query?: string}  $context
     * @return array{keyword: string, alternatives: array<string>, score: float, reason: string}
     */
    public function suggestKeyword(array $context): array
    {
        $title = trim((string) ($context['title'] ?? ''));
        $contentType = $context['content_type'] ?? 'article';
        $subtype = $context['subtype'] ?? '';

        if ($title === '') {
            return [
                'keyword' => '',
                'alternatives' => [],
                'score' => 0,
                'reason' => 'عنوان خالی است',
            ];
        }

        $keywords = $this->extractKeywords($title);
        $candidates = $this->generateCandidates($keywords, $contentType, $subtype, $title);

        $scored = [];
        foreach ($candidates as $candidate) {
            $factors = $this->calculateMultiFactorScore($candidate, $keywords, $contentType, $subtype, $title);
            $scored[] = [
                'keyword' => $candidate,
                'score' => $factors['total'],
                'reason' => $this->generateDetailedReason($candidate, $factors, $contentType, $subtype),
            ];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        $main = $scored[0] ?? ['keyword' => '', 'score' => 0, 'reason' => ''];
        $alts = array_slice(array_map(fn($s) => [
            'keyword' => $s['keyword'],
            'score' => $s['score'],
            'reason' => $s['reason'],
        ], $scored), 1, 4);

        $ruleResult = [
            'keyword' => $main['keyword'],
            'alternatives' => $alts,
            'score' => $main['score'],
            'reason' => $main['reason'],
            'competition_warning' => $this->competitionWarning($title, $keywords),
        ];

        if ($this->isAiAvailable()) {
            $aiResult = $this->suggestKeywordFromAi($title, $contentType, $subtype);
            if ($aiResult && count($aiResult) > 0) {
                $mainAi = $aiResult[0];
                $altsAi = array_slice(array_map(fn($k) => [
                    'keyword' => $k['keyword'] ?? '',
                    'score' => $k['score'] ?? 50,
                    'reason' => ($k['reason'] ?? '') . ' | AI Enhanced',
                ], $aiResult), 1, 4);

                return [
                    'keyword' => $mainAi['keyword'] ?? $ruleResult['keyword'],
                    'alternatives' => $altsAi,
                    'score' => max($ruleResult['score'], $mainAi['score'] ?? 50),
                    'reason' => ($mainAi['reason'] ?? $ruleResult['reason']) . ' | AI Enhanced',
                    'competition_warning' => $ruleResult['competition_warning'] ?? null,
                ];
            }
        }

        return $ruleResult;
    }

private function extractKeywords(string $title): array
    {
        // جدا کردن کلمات
        $words = preg_split('/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false) {
            return [];
        }

        // حذف stop words و واژه‌های کوتاه
        $keywords = array_filter($words, function (string $word): bool {
            $word = trim($word);
            if (mb_strlen($word, 'UTF-8') < 2) {
                return false;
            }
            return !in_array($word, self::STOP_WORDS, true);
        });

        return array_values($keywords);
    }

    /**
     * انتخاب واژه کلیدی اصلی
     */
    private function selectMainKeyword(array $keywords, string $contentType, string $subtype): string
    {
        if (empty($keywords)) {
            return '';
        }

        // اگر فقط یک واژه داریم
        if (count($keywords) === 1) {
            return $keywords[0];
        }

        // برای محصولات، نام محصول معمولاً آخرین واژه‌هاست
        if ($contentType === 'product') {
            // مثلاً 'پک اقتصادی شیگلم' → 'شیگلم' یا 'پک شیگلم'
            $lastTwo = implode(' ', array_slice($keywords, -2));
            if (count($keywords) >= 2) {
                return $lastTwo;
            }
        }

        // برای مقالات، ترکیب ۲-۳ واژه اصلی
        if ($contentType === 'article') {
            // حذف واژه‌های خیلی کوتاه
            $significant = array_filter($keywords, fn ($k) => mb_strlen($k, 'UTF-8') > 2);
            if (count($significant) >= 3) {
                return implode(' ', array_slice($significant, 0, 3));
            } elseif (count($significant) >= 2) {
                return implode(' ', array_slice($significant, 0, 2));
            }
        }

        // پیش‌فرض: اولین ۲ واژه معنادار
        return implode(' ', array_slice($keywords, 0, min(2, count($keywords))));
    }

    /**
     * تولید پیشنهادهای جایگزین
     */
    private function generateAlternatives(string $mainKeyword, array $allKeywords, string $contentType, string $subtype): array
    {
        $alternatives = [];

        // ۱) واژه‌های باقی‌مانده از عنوان
        foreach ($allKeywords as $keyword) {
            if ($keyword !== $mainKeyword) {
                $alternatives[] = $keyword;
            }
        }

        // ۲) ترکیب واژه‌ها
        if (count($allKeywords) >= 2) {
            $pairs = [];
            for ($i = 0; $i < count($allKeywords); $i++) {
                for ($j = $i + 1; $j < count($allKeywords); $j++) {
                    if ($allKeywords[$i] !== $mainKeyword && $allKeywords[$j] !== $mainKeyword) {
                        $pairs[] = $allKeywords[$i].' '.$allKeywords[$j];
                    }
                }
            }
            $alternatives = array_merge($alternatives, array_slice($pairs, 0, 2));
        }

        // ۳) پیشنهاد بر اساس نوع محتва
        $contextual = $this->getContextualSuggestions($mainKeyword, $contentType, $subtype);
        $alternatives = array_merge($alternatives, $contextual);

        // حذف تکراری‌ها
        $alternatives = array_unique(array_filter($alternatives, fn ($a) => $a !== '' && $a !== $mainKeyword));

        return array_values($alternatives);
    }

    /**
     * پیشنهادهای مبتنی بر زمینه
     */
    private function getContextualSuggestions(string $keyword, string $contentType, string $subtype): array
    {
        $suggestions = [];

        switch ($subtype) {
            case 'tutorial':
                $suggestions[] = 'آموزش '.$keyword;
                $suggestions[] = 'راهنمای '.$keyword;
                break;
            case 'comparison':
                $suggestions[] = 'مقایسه '.$keyword;
                $suggestions[] = $keyword.' vs';
                break;
            case 'review':
                $suggestions[] = 'بررسی '.$keyword;
                $suggestions[] = 'نقد '.$keyword;
                break;
            case 'listicle':
                $suggestions[] = 'بهترین '.$keyword;
                $suggestions[] = 'برترین '.$keyword;
                break;
            default:
                $suggestions[] = 'راهنمای '.$keyword;
                $suggestions[] = 'معرفی '.$keyword;
        }

        return $suggestions;
    }

    /**
     * محاسبه امتیاز کلمه کلیدی (0-100)
     */
    private function calculateScore(string $keyword, string $contentType, string $subtype): float
    {
        if ($keyword === '') {
            return 0;
        }

        $score = 50.0; // امتیاز پایه

        // طول کلمه کلیدی (۳-۵ کلمه ایده‌آل)
        $wordCount = count(explode(' ', $keyword));
        if ($wordCount >= 3 && $wordCount <= 5) {
            $score += 15;
        } elseif ($wordCount >= 2 && $wordCount <= 6) {
            $score += 10;
        }

        // وجود واژه‌های جذاب
        $attractiveWords = ['بهترین', 'برترین', 'مقایسه', 'بررسی', 'آموزش', 'راهنما', 'ترفند'];
        foreach ($attractiveWords as $aw) {
            if (str_contains($keyword, $aw)) {
                $score += 5;
            }
        }

        // طول مناسب (بیش از ۱۰ کاراکتر)
        if (mb_strlen($keyword, 'UTF-8') > 10) {
            $score += 10;
        }

        // جریمه برای واژه‌های خیلی کوتاه
        if (mb_strlen($keyword, 'UTF-8') < 5) {
            $score -= 10;
        }

        return min(100, max(0, $score));
    }

    /**
     * تولید دلیل پیشنهاد
     */
    private function generateReason(string $keyword, string $contentType, string $subtype): string
    {
        if ($keyword === '') {
            return 'کلمه کلیدی پیشنهداده نشد';
        }

        $reasons = [];
        $wordCount = count(explode(' ', $keyword));
        $charLen = mb_strlen($keyword, 'UTF-8');

        // تصمیم طول
        if ($wordCount >= 3 && $wordCount <= 5) {
            $reasons[] = 'کلمه بلند (ایدهاف '.$wordCount.' کلمه)';
        } elseif ($wordCount >= 2) {
            $reasons[] = 'ترکیب (دو کلمه)';
        } else {
            $reasons[] = 'کلمه اصلی';
        }

        // رقابت
        if ($wordCount >= 3) {
            $reasons[] = 'رقابت کمتر به جستجو';
        }

        // الگوی رایج
        foreach (self::TITLE_PATTERNS as $type => $pattern) {
            foreach ($pattern['patterns'] as $p) {
                if (str_contains($keyword, $p)) {
                    $reasons[] = 'الگوی رایج: '.$p;
                    break 2;
                }
            }
        }

        // نوع محتوا
        $typeLabels = [
            'article' => 'مقاله',
            'product' => 'محصول',
            'landing' => 'لندینگ',
        ];
        if (isset($typeLabels[$contentType])) {
            $reasons[] = 'مناسب برای '.$typeLabels[$contentType];
        }

        // طول کافی
        if ($charLen > 15) {
            $reasons[] = 'طول کافی برای سختن جستجو';
        }

        return implode(' | ', $reasons) ?: 'کلمه کلیدی اصلی';
    }

    /**
     * تولید کاندیداهای کلمه کلیدی
     */
    private function generateCandidates(array $keywords, string $contentType, string $subtype, string $title): array
    {
        $candidates = [];
        $candidates[] = $title;

        if (count($keywords) >= 2) {
            $candidates[] = implode(' ', array_slice($keywords, 0, min(5, count($keywords))));
        }
        if (count($keywords) >= 3) {
            $candidates[] = $keywords[0] . ' ' . end($keywords);
        }

        $base = implode(' ', array_slice($keywords, 0, 3));
        $subMap = [
            'tutorial' => ['آموزش ' . $base, 'راهنمای ' . $base],
            'comparison' => ['مقایسه ' . $base],
            'review' => ['بررسی ' . $base, 'معرفی ' . $base],
            'listicle' => ['بهترین ' . $base],
        ];
        if (isset($subMap[$subtype])) {
            $candidates = array_merge($candidates, $subMap[$subtype]);
        } else {
            $candidates[] = 'راهنمای ' . $base;
        }

        $base2 = implode(' ', array_slice($keywords, 0, 2));
        if ($contentType === 'product') {
            $candidates[] = 'خرید ' . $base2;
            $candidates[] = 'قیمت ' . $base2;
        } else {
            $candidates[] = $base2 . ' برای مبتداین';
        }

        foreach (self::SEASONAL_PATTERNS as $season => $suffix) {
            foreach ($keywords as $kw) {
                if (str_contains($kw, $season)) {
                    $candidates[] = $kw . ' در ' . $suffix;
                }
            }
        }

        return array_values(array_unique(array_filter($candidates, fn($c) => $c !== '')));
    }

    /**
     * امتیازدهی چند فاکتوره
     */
    private function calculateMultiFactorScore(string $candidate, array $titleKeywords, string $contentType, string $subtype, string $title): array
    {
        $wordCount = count(explode(' ', $candidate));
        $charLen = mb_strlen($candidate, 'UTF-8');
        $f = [];

        $f['length'] = match(true) {
            $wordCount >= 3 && $wordCount <= 5 => 25,
            $wordCount >= 2 && $wordCount <= 6 => 18,
            $wordCount === 1 => 8,
            default => 12,
        };

        $f['relevance'] = 0;
        $titleStr = implode(' ', $titleKeywords);
        similar_text($candidate, $titleStr, $pct);
        $f['relevance'] = round($pct * 0.25);

        $f['attractive'] = min(20, count(array_filter(self::ATTRACTIVE_WORDS, fn($aw) => str_contains($candidate, $aw))) * 5);

        $f['subtype'] = 0;
        foreach (self::TITLE_PATTERNS as $type => $pattern) {
            foreach ($pattern['patterns'] as $p) {
                if (str_contains($candidate, $p)) {
                    $f['subtype'] = round(15 * $pattern['weight']);
                    break 2;
                }
            }
        }

        $f['seo_length'] = match(true) {
            $charLen >= 15 && $charLen <= 50 => 15,
            $charLen >= 10 && $charLen <= 60 => 10,
            default => 5,
        };

        $f['total'] = array_sum($f);
        return $f;
    }

    /**
     * تولید دلیل دقیق
     */
    private function generateDetailedReason(string $keyword, array $factors, string $contentType, string $subtype): string
    {
        $reasons = [];
        $wordCount = count(explode(' ', $keyword));
        $charLen = mb_strlen($keyword, 'UTF-8');

        if ($wordCount >= 3 && $wordCount <= 5) {
            $reasons[] = 'کلمه بلند (' . $wordCount . ' کلمه)';
        } elseif ($wordCount >= 2) {
            $reasons[] = 'ترکیب';
        } else {
            $reasons[] = 'کلمه اصلی';
        }

        if ($wordCount >= 3) {
            $reasons[] = 'رقابت کمتر';
        }

        foreach (self::TITLE_PATTERNS as $type => $pattern) {
            foreach ($pattern['patterns'] as $p) {
                if (str_contains($keyword, $p)) {
                    $reasons[] = 'الگوی: ' . $p;
                    break 2;
                }
            }
        }

        $typeLabels = [
            'article' => 'مقاله',
            'product' => 'محصول',
            'landing' => 'لندینگ',
        ];
        if (isset($typeLabels[$contentType])) {
            $reasons[] = $typeLabels[$contentType];
        }

        if ($charLen >= 15 && $charLen <= 50) {
            $reasons[] = 'طول ایدهال سئو';
        }

        return implode(' | ', $reasons) ?: 'کلمه اصلی';
    }

    /**
     * پیشنهاد کلمه کلیدی از AI (fallback)
     */
    private function suggestKeywordFromAi(string $title, string $contentType, string $subtype): ?array
    {
        try {
            $gateway = app(\App\Domains\Ai\Services\AiGateway::class);

            $system = 'تو یک تحلیلگر کلمه کلیدی هستی. بر اساس عنوان، ۵ کلمه کلیدی با امتیاز 0-100 پیشنهاد بده. خروج JSON: ' . '{"keywords": [{"keyword": "...", "score": 80, "reason": "..."}]}';

            $user = "عنوان: " . $title . "
نوع محتوا: " . $contentType . "
زیرنوع: " . $subtype;

            $result = $gateway->generate($system, $user, 'keyword_suggestion');

            $content = $result['content'] ?? '';
            $json = json_decode($content, true);

            if ($json && isset($json['keywords']) && count($json['keywords']) >= 2) {
                return $json['keywords'];
            }

            // تلاش JSON از markdown
            if (preg_match('//', $content, $m)) {
                $json = json_decode($m[1], true);
                if ($json && isset($json['keywords'])) {
                    return $json['keywords'];
                }
            }

            return null;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ContentAnalyzer: AI fallback failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * ڌوشتن دسترسی کلید اصلی از AI برای توصیه دهی
     */
    private function fallbackToAi(array $context): array
    {
        $title = trim($context['title'] ?? '');
        $contentType = $context['content_type'] ?? 'article';
        $subtype = $context['subtype'] ?? '';

        $aiKeywords = $this->suggestKeywordFromAi($title, $contentType, $subtype);

        if ($aiKeywords && count($aiKeywords) > 0) {
            $main = $aiKeywords[0];
            $alts = array_slice($aiKeywords, 1, 4);

            return [
                'keyword' => $main['keyword'] ?? $title,
                'alternatives' => array_map(fn($k) => [
                    'keyword' => $k['keyword'] ?? '',
                    'score' => $k['score'] ?? 50,
                    'reason' => ($k['reason'] ?? '') . ' | پیشنهاد AI',
                ], $alts),
                'score' => $main['score'] ?? 50,
                'reason' => ($main['reason'] ?? '') . ' | پیشنهاد AI',
            ];
        }

        // از AI هم جواب نگرفت - برگردن نتیجه rule-based
        return $this->suggestKeyword($context);
    }

    public function isAiAvailable(): bool
    {
        try {
            $org = app(\App\Domains\Organization\Contracts\CurrentOrganization::class)->get();
            if (!$org) return false;

            return \Illuminate\Support\Facades\DB::table('ai_provider_settings')
                ->where('organization_id', $org->getKey())
                ->where('status', 'active')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }
    /**
     * هشدار رقابت برای عنوان‌های تک‌کلمه‌ای (IL5.1) — «مکمل»، «کرم»، «لپ‌تاپ»
     * هدترم‌های فوق‌رقابتی هستند؛ کاربر باید به سمت long-tail هدایت شود.
     */
    private function competitionWarning(string $title, array $keywords): ?array
    {
        if (count($keywords) <= 1) {
            return [
                'type' => 'single_word_high_competition',
                'message' => 'این عنوان تک‌کلمه‌ای بسیار رقابتی است و رقابت بالایی در نتایج گوگل دارد. پیشنهاد می‌شود از نسخهٔ long-tail (مثلاً «راهنمای خرید '.$title.'» یا «بهترین '.$title.' برای پوست چرب») استفاده کنید تا شانس رتبه‌گیری بالاتر رود.',
                'suggestions' => [
                    'راهنمای جامع '.$title,
                    'بهترین '.$title.' برای نیازهای مختلف',
                    'راهنمای خرید '.$title.' در ۱۴۰۵',
                ],
            ];
        }

        return null;
    }
}