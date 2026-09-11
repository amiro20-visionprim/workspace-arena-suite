<?php

declare(strict_types=1);

namespace App\Domains\Content\Services;

use Illuminate\Support\Facades\DB;

/**
 * بهینه‌ساز عنوان — تولید عنوان‌های جایگزین با امتیازدهی.
 *
 * برای هر عنوان:
 *   ۱) تحلیل نقاط ضعف (طول، کلمات کلیدی، الگوی SEO)
 *   ۲) تولید ۳-۵ عنوان جایگزین
 *   ۳) امتیازدهی هر عنوان (طول + کلمات کلیدی + جذابیت + ساختار)
 *   ۴) پیشنهاد بهترین گزینه
 */
class TitleOptimizer
{
    /** الگوهای عنوان SEO */
    private const PATTERNS = [
        'list' => [' بهترین ', ' برترین ', ' ۱۰ ', ' ۵ ', ' لیست '],
        'howto' => [' آموزش ', ' راهنمای ', ' نحوه ', ' چطور ', ' گام به گام '],
        'review' => [' بررسی ', ' نقد ', ' مقایسه ', ' تجربه '],
        'year' => [' ۲۰۲۶', ' ۲۰۲۵', ' جدیدترین ', ' به‌روز '],
    ];

    /** کلمات ایست فارسی */
    private const STOP_WORDS = ['و', 'در', 'به', 'از', 'با', 'برای', 'که', 'این', 'آن', 'را', 'است', 'شد', 'شده'];

    /**
     * تولید عنوان‌های جایگزین برای یک محتوا.
     *
     * @return array{title: string, score: int, reasons: string[], alternatives: array{title: string, score: int, reasons: string[]}[]}
     */
    public function optimize(int $draftId): ?array
    {
        $draft = DB::table('content_drafts')->where('id', $draftId)->first();
        if (! $draft) {
            return null;
        }

        $currentTitle = $draft->title ?? '';
        $content = $draft->content ?? '';
        $keywords = $this->extractKeywords($currentTitle.' '.$content);

        // تحلیل عنوان فعلی
        $currentAnalysis = $this->analyzeTitle($currentTitle, $keywords);

        // تولید عنوان‌های جایگزین
        $alternatives = $this->generateAlternatives($currentTitle, $keywords, $draft->subtype ?? 'article');

        // مرتب‌سازی بر اساس امتیاز
        usort($alternatives, fn ($a, $b) => $b['score'] <=> $a['score']);

        return [
            'draft_id' => $draftId,
            'current_title' => $currentTitle,
            'current_score' => $currentAnalysis['score'],
            'current_reasons' => $currentAnalysis['reasons'],
            'alternatives' => array_slice($alternatives, 0, 5),
            'keywords' => array_slice($keywords, 0, 10),
        ];
    }

    /**
     * تحلیل کیفیت یک عنوان.
     */
    private function analyzeTitle(string $title, array $keywords): array
    {
        $score = 50; // امتیاز پایه
        $reasons = [];

        $length = mb_strlen($title, 'UTF-8');

        // بررسی طول
        if ($length < 10) {
            $score -= 20;
            $reasons[] = 'عنوان خیلی کوتاه است (< ۱۰ کاراکتر)';
        } elseif ($length >= 30 && $length <= 60) {
            $score += 15;
            $reasons[] = 'طول ایده‌آل SEO (۳۰-۶۰ کاراکتر)';
        } elseif ($length > 70) {
            $score -= 10;
            $reasons[] = 'عنوان خیلی بلند است (> ۷۰ کاراکتر)';
        }

        // بررسی الگوی SEO
        foreach (self::PATTERNS as $pattern => $triggers) {
            foreach ($triggers as $trigger) {
                if (mb_str_contains($title, $trigger, false, 'UTF-8')) {
                    $score += 10;
                    $reasons[] = "الگوی {$pattern} موجود است";
                    break 2;
                }
            }
        }

        // بررسی عدد در عنوان
        if (preg_match('/\d+/', $title)) {
            $score += 5;
            $reasons[] = 'عدد در عنوان موجود است (جذابیت بیشتر)';
        }

        // بررسی سؤالی بودن
        if (mb_str_contains($title, '؟', false, 'UTF-8') || mb_str_contains($title, '?', false, 'UTF-8')) {
            $score += 5;
            $reasons[] = 'عنوان سؤالی است (جذابیت بیشتر)';
        }

        // بررسی کلمه کلیدی
        $hasKeyword = false;
        foreach ($keywords as $kw) {
            if (mb_str_contains($title, $kw, false, 'UTF-8')) {
                $hasKeyword = true;
                break;
            }
        }
        if ($hasKeyword) {
            $score += 10;
            $reasons[] = 'کلمه کلیدی اصلی در عنوان موجود است';
        } else {
            $score -= 10;
            $reasons[] = 'کلمه کلیدی اصلی در عنوان نیست';
        }

        return ['score' => max(0, min(100, $score)), 'reasons' => $reasons];
    }

    /**
     * تولید عنوان‌های جایگزین.
     */
    private function generateAlternatives(string $currentTitle, array $keywords, string $subtype): array
    {
        $alternatives = [];
        $mainKeyword = $keywords[0] ?? '';
        $topic = $this->extractTopic($currentTitle);

        // ۱) الگوی لیست
        if ($mainKeyword !== '') {
            $alt = "بهترین {$topic} در سال ۲۰۲۶ — راهنمای جامع خرید";
            $alternatives[] = $this->scoreAlternative($alt, $keywords, 'لیست + سال');
        }

        // ۲) الگوی آموزش
        if ($mainKeyword !== '') {
            $alt = "آموزش کامل {$topic} — از صفر تا صد";
            $alternatives[] = $this->scoreAlternative($alt, $keywords, 'آموزش');
        }

        // ۳) الگوی بررسی
        if ($mainKeyword !== '') {
            $alt = "بررسی تخصصی {$topic} — نقاط قوت و ضعف";
            $alternatives[] = $this->scoreAlternative($alt, $keywords, 'بررسی');
        }

        // ۴) الگوی راهنما
        if ($mainKeyword !== '') {
            $alt = "راهنمای خرید {$topic} — نکات مهم قبل از خرید";
            $alternatives[] = $this->scoreAlternative($alt, $keywords, 'راهنما');
        }

        // ۵) الگوی مقایسه
        if ($mainKeyword !== '') {
            $alt = "مقایسه {$topic} — کدام بهتر است؟";
            $alternatives[] = $this->scoreAlternative($alt, $keywords, 'مقایسه + سؤال');
        }

        return $alternatives;
    }

    /**
     * امتیازدهی یک عنوان جایگزین.
     */
    private function scoreAlternative(string $title, array $keywords, string $pattern): array
    {
        $analysis = $this->analyzeTitle($title, $keywords);
        $analysis['pattern'] = $pattern;
        return $analysis;
    }

    /**
     * استخراج موضوع از عنوان.
     */
    private function extractTopic(string $title): string
    {
        // حذف کلمات ایست
        $words = preg_split('/[\s\-,\.]+/', $title, -1, PREG_SPLIT_NO_EMPTY);
        $topicWords = [];
        foreach ($words as $word) {
            $word = trim($word);
            $lower = mb_strtolower($word, 'UTF-8');
            if (mb_strlen($word, 'UTF-8') < 3 || in_array($lower, self::STOP_WORDS, true)) {
                continue;
            }
            $topicWords[] = $word;
        }
        return implode(' ', array_slice($topicWords, 0, 3));
    }

    /**
     * استخراج کلمات کلیدی معنادار.
     */
    private function extractKeywords(string $text): array
    {
        $words = preg_split('/[\s\-,\.]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $keywords = [];
        $seen = [];
        foreach ($words as $word) {
            $word = trim($word);
            $lower = mb_strtolower($word, 'UTF-8');
            if (mb_strlen($word, 'UTF-8') < 3 || in_array($lower, self::STOP_WORDS, true) || isset($seen[$lower])) {
                continue;
            }
            $seen[$lower] = true;
            $keywords[] = $word;
        }
        return $keywords;
    }
}
