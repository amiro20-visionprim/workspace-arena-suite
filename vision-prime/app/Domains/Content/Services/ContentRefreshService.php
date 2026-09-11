<?php

declare(strict_types=1);

namespace App\Domains\Content\Services;

use Illuminate\Support\Facades\DB;

/**
 * سرویس بروزرسانی محتوای قدیمی.
 *
 * شناسایی محتوایی که:
 *   ۱) قدیمی شده (بدون بروزرسانی)
 *   ۲) کیفیت پایینی داره
 *   ۳) عنوان ضعیفی داره
 *   ۴) تراکم کلمات کلیدی نامناسبه
 *
 * برای هر مورد پیشنهاد بروزرسانی میده.
 */
class ContentRefreshService
{
    private const STALE_DAYS = 60;
    private const LOW_QUALITY_THRESHOLD = 70;
    private const WEAK_TITLE_LENGTH = 15;

    /**
     * شناسایی محتوای نیاز به بروزرسانی.
     *
     * @return array{stale: array, low_quality: array, weak_titles: array, total: int}
     */
    public function findRefreshCandidates(int $siteId): array
    {
        $drafts = DB::table('content_drafts')
            ->where('site_id', $siteId)
            ->orderByDesc('created_at')
            ->get();

        $stale = [];
        $lowQuality = [];
        $weakTitles = [];

        foreach ($drafts as $draft) {
            $title = $draft->title ?? '';
            $content = $draft->content ?? '';
            $quality = $draft->quality_score ?? 0;
            $createdAt = $draft->created_at;
            $updatedAt = $draft->updated_at ?? $createdAt;

            // بررسی قدیمی بودن
            $daysSinceUpdate = now()->diffInDays($updatedAt);
            if ($daysSinceUpdate > self::STALE_DAYS) {
                $stale[] = [
                    'id' => $draft->id,
                    'title' => $title,
                    'days_since_update' => $daysSinceUpdate,
                    'quality_score' => $quality,
                    'reason' => "{$daysSinceUpdate} روز بدون بروزرسانی",
                ];
            }

            // بررسی کیفیت پایین
            if ($quality < self::LOW_QUALITY_THRESHOLD && $quality > 0) {
                $lowQuality[] = [
                    'id' => $draft->id,
                    'title' => $title,
                    'quality_score' => $quality,
                    'reason' => "امتیاز کیفیت {$quality}/۱۰۰ (زیر آستانه)",
                ];
            }

            // بررسی عنوان ضعیف
            if (mb_strlen($title, 'UTF-8') < self::WEAK_TITLE_LENGTH) {
                $weakTitles[] = [
                    'id' => $draft->id,
                    'title' => $title,
                    'length' => mb_strlen($title, 'UTF-8'),
                    'reason' => "عنوان خیلی کوتاه ({$title})",
                ];
            }
        }

        return [
            'stale' => $stale,
            'low_quality' => $lowQuality,
            'weak_titles' => $weakTitles,
            'total' => count($stale) + count($lowQuality) + count($weakTitles),
        ];
    }

    /**
     * پیشنهاد بروزرسانی برای یک محتوای خاص.
     */
    public function suggestRefresh(int $draftId): ?array
    {
        $draft = DB::table('content_drafts')->where('id', $draftId)->first();
        if (! $draft) {
            return null;
        }

        $title = $draft->title ?? '';
        $content = $draft->content ?? '';
        $quality = $draft->quality_score ?? 0;

        $suggestions = [];

        // پیشنهاد عنوان
        $optimizer = app(TitleOptimizer::class);
        $titleAnalysis = $optimizer->optimize($draftId);
        if ($titleAnalysis && ! empty($titleAnalysis['alternatives'])) {
            $suggestions[] = [
                'type' => 'title',
                'priority' => 'high',
                'current' => $title,
                'suggested' => $titleAnalysis['alternatives'][0]['title'],
                'score' => $titleAnalysis['alternatives'][0]['score'],
            ];
        }

        // پیشنهاد بروزرسانی محتوا
        $wordCount = preg_match_all('/[\p{L}\p{N}]+/u', $content);
        if ($wordCount < 800) {
            $suggestions[] = [
                'type' => 'content_length',
                'priority' => 'medium',
                'current' => "{$wordCount} کلمه",
                'suggested' => 'حداقل ۸۰۰ کلمه',
                'reason' => 'محتوای کوتاه رتبه بهتری نمیگیره',
            ];
        }

        // پیشنهاد بروزرسانی کیفیت
        if ($quality < self::LOW_QUALITY_THRESHOLD) {
            $suggestions[] = [
                'type' => 'quality',
                'priority' => 'high',
                'current' => "{$quality}/۱۰۰",
                'suggested' => 'بازنویسی با کیفیت بالاتر',
                'reason' => 'امتیاز کیفیت پایین',
            ];
        }

        return [
            'draft_id' => $draftId,
            'title' => $title,
            'suggestions' => $suggestions,
        ];
    }
}
