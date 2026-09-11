<?php

namespace App\Domains\Content\Services;

class CategoryMatcher
{
    private const GENERIC_WORDS = [
        'ایرانی', 'ایران', 'تست', 'جدید', 'بهترین', 'بدترین',
        'های', 'های', 'یک', 'با', 'از', 'در', 'برای', 'به',
    ];

    public function suggest(array $categories, string $title, string $keyword = '', string $contentType = 'article'): array
    {
        $titleLower = mb_strtolower(trim($title));
        $keywordLower = mb_strtolower(trim($keyword));
        $titleWords = array_values(array_filter(explode(' ', $titleLower), fn($w) => mb_strlen($w) > 1));

        $scored = [];
        foreach ($categories as $cat) {
            $catName = $cat['name'] ?? '';
            $catLower = mb_strtolower($catName);
            $catWords = array_values(array_filter(explode(' ', $catLower), fn($w) => mb_strlen($w) > 1));
            $score = 0;
            $reasons = [];

            // F1: Title words found IN category name
            // FIXED: reduced single-word score, added generic word penalty
            $foundWords = 0;
            $foundMeaningful = 0;
            foreach ($titleWords as $w) {
                if (mb_strlen($w) >= 2 && mb_strpos($catLower, $w) !== false) {
                    $foundWords++;
                    if (!in_array($w, self::GENERIC_WORDS)) {
                        $foundMeaningful++;
                    }
                }
            }
            if ($foundWords >= 3) { $score += 45; }
            elseif ($foundWords == 2) {
                // Penalty if both words are generic
                if ($foundMeaningful >= 2) { $score += 38; }
                elseif ($foundMeaningful == 1) { $score += 25; }
                else { $score += 15; }
            }
            elseif ($foundWords == 1) {
                // FIXED: single generic word gets very low score
                if ($foundMeaningful == 1) { $score += 20; }
                else { $score += 8; }  // generic word like "ایرانی" = only 8 points
            }
            if ($foundWords > 0) { $reasons[] = 'تطبیق ' . $foundWords . ' کلمه'; }

            // Bonus: category first word matches title (reduced from 20 to 12)
            $firstCatWord = $catWords[0] ?? '';
            if ($firstCatWord && mb_strlen($firstCatWord) >= 2 && !in_array($firstCatWord, self::GENERIC_WORDS)) {
                foreach ($titleWords as $tw) {
                    if (mb_strpos($tw, $firstCatWord) !== false || mb_strpos($firstCatWord, $tw) !== false) {
                        $score += 12;
                        $reasons[] = 'تطبیق کلمه اصلی';
                        break;
                    }
                }
            }

            // F2: Category words found IN title
            $catFound = 0;
            foreach ($catWords as $cw) {
                if (mb_strlen($cw) >= 2 && mb_strpos($titleLower, $cw) !== false) { $catFound++; }
            }
            if ($catFound >= 2) { $score += 25; $reasons[] = 'تطبیق عنوان'; }
            elseif ($catFound == 1) { $score += 12; $reasons[] = 'تطبیق عنوان'; }

            // F2.5: Exact or near-exact name match (very high confidence)
            if ($catLower === $titleLower) {
                $score += 35; $reasons[] = 'تطبیق دقیق نام';
            } elseif (mb_strlen($titleLower) >= 3 && mb_strpos($catLower, $titleLower) !== false) {
                $score += 20; $reasons[] = 'عنوان در نام دسته';
            } elseif (mb_strlen($catLower) >= 3 && mb_strpos($titleLower, $catLower) !== false) {
                $score += 20; $reasons[] = 'نام دسته در عنوان';
            }

            // F3: Full containment (fallback for partial matches)
            if (mb_strpos($catLower, $titleLower) !== false || mb_strpos($titleLower, $catLower) !== false) {
                $score += 10; $reasons[] = 'تطبیق کامل';
            }

            // F4: Popularity (reduced max from 5 to 3)
            $count = $cat['post_count'] ?? 0;
            if ($count > 20) { $score += 3; $reasons[] = 'پرمحتوا'; }
            elseif ($count > 10) { $score += 2; }

            $total = min(100, $score);
            $scored[] = [
                'id' => $cat['id'] ?? 0,
                'name' => $catName,
                'score' => $total,
                'confidence' => $this->confidence($total),
                'is_new' => false,
                'reason' => implode(' | ', $reasons),
            ];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        $suggestions = array_slice($scored, 0, 5);

        $note = null;
        if (empty($suggestions)) { $note = 'دسته‌ای یافت نشد.'; }
        elseif ($suggestions[0]['score'] < 15) { $note = 'پیشنهادها بر اساس تحلیل محتوا هستند.'; }

        // FIXED: raise threshold from 50 to 55 for new category suggestion
        $newCategorySuggested = false;
        $newCategoryName = null;
        if (empty($suggestions) || $suggestions[0]['score'] < 55) {
            $newCategorySuggested = true;
            $newCategoryName = $this->suggestNewCategoryName($title, $keyword);
        }

        return [
            'suggestions' => $suggestions,
            'note' => $note,
            'best_id' => $suggestions[0]['id'] ?? null,
            'new_category_suggested' => $newCategorySuggested,
            'new_category_name' => $newCategoryName,
        ];
    }

    private function suggestNewCategoryName(string $title, string $keyword): string
    {
        $titleLower = mb_strtolower(trim($title));
        $words = array_filter(explode(' ', $titleLower), fn($w) => mb_strlen($w) > 2);
        $stopWords = ['و', 'در', 'با', 'از', 'برای', 'به', 'که', 'این', 'آن', 'را', 'شد', 'ها', 'های', 'یک', 'تا', 'نیز', 'های'];
        $meaningful = array_values(array_filter($words, fn($w) => !in_array($w, $stopWords)));
        if (empty($meaningful)) { return mb_substr($title, 0, 40); }
        $catWords = array_slice($meaningful, 0, 3);
        return implode(' ', $catWords);
    }

    private function confidence(float $score): string
    {
        if ($score >= 55) return 'high';
        if ($score >= 30) return 'medium';
        return 'low';
    }
}
