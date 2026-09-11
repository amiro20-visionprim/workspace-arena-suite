<?php

declare(strict_types=1);

namespace App\Domains\Content\Services;

use Illuminate\Support\Facades\DB;

/**
 * سرویس A/B تست عنوان.
 *
 * قابلیت‌ها:
 *   ۱) ایجاد تست با ۲-۵ عنوان جایگزین
 *   ۲) ردیابی impression + click هر عنوان
 *   ۳) محاسبه اطمینان آماری (Z-test برای نسبت‌ها)
 *   ۴) تعیین خودکار برنده وقتی به اطمینان کافی رسید
 *   ۵) پیشنهاد خودکار بهترین عنوان
 */
class TitleAbTestService
{
    private const MIN_CONFIDENCE = 95.0; // حداقل اطمینان آماری (%)
    private const Z_SCORE_95 = 1.96; // مقدار Z برای 95% اطمینان

    /**
     * ایجاد تست A/B جدید.
     */
    public function createTest(
        int $siteId,
        string $originalTitle,
        array $variantTitles,
        int $draftId = null,
        int $minImpressions = 100,
        int $durationDays = 7,
        bool $autoUpdate = false,
        int $wordpressPostId = null,
    ): array {
        // اعتبارسنجی
        if (count($variantTitles) < 1 || count($variantTitles) > 5) {
            throw new \InvalidArgumentException('باید ۱ تا ۵ عنوان جایگزین وارد کنید');
        }

        // ایجاد تست
        $testId = DB::table('title_ab_tests')->insertGetId([
            'site_id' => $siteId,
            'draft_id' => $draftId,
            'original_title' => $originalTitle,
            'status' => 'draft',
            'min_impressions' => $minImpressions,
            'duration_days' => $durationDays,
            'auto_update' => $autoUpdate,
            'wordpress_post_id' => $wordpressPostId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // افزودن عنوان اصلی به عنوان variant
        DB::table('title_ab_variants')->insert([
            'test_id' => $testId,
            'title' => $originalTitle,
            'is_original' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // افزودن عنوان‌های جایگزین
        foreach ($variantTitles as $title) {
            DB::table('title_ab_variants')->insert([
                'test_id' => $testId,
                'title' => $title,
                'is_original' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->getTest($testId);
    }

    /**
     * شروع تست.
     */
    public function startTest(int $testId): array
    {
        DB::table('title_ab_tests')
            ->where('id', $testId)
            ->update([
                'status' => 'running',
                'started_at' => now(),
                'updated_at' => now(),
            ]);

        return $this->getTest($testId);
    }

    /**
     * توقف تست.
     */
    public function pauseTest(int $testId): array
    {
        DB::table('title_ab_tests')
            ->where('id', $testId)
            ->update(['status' => 'paused', 'updated_at' => now()]);

        return $this->getTest($testId);
    }

    /**
     * ردیابی رویداد (impression یا click).
     */
    public function trackEvent(int $variantId, string $eventType, string $url = null, string $visitorHash = null): void
    {
        DB::table('title_ab_events')->insert([
            'variant_id' => $variantId,
            'event_type' => $eventType,
            'visitor_hash' => $visitorHash,
            'url' => $url,
            'created_at' => now(),
        ]);

        // آپدیت شمارنده‌های variant
        $column = $eventType === 'click' ? 'clicks' : 'impressions';
        DB::table('title_ab_variants')
            ->where('id', $variantId)
            ->increment($column);

        // محاسبه مجدد CTR
        $variant = DB::table('title_ab_variants')->where('id', $variantId)->first();
        if ($variant && $variant->impressions > 0) {
            $ctr = ($variant->clicks / $variant->impressions) * 100;
            DB::table('title_ab_variants')
                ->where('id', $variantId)
                ->update(['ctr' => round($ctr, 4)]);
        }

        // بررسی اینکه آیا تست باید متوقف بشه
        $this->checkTestCompletion($variant->test_id ?? 0);
    }

    /**
     * بررسی اتمام تست و تعیین برنده.
     */
    private function checkTestCompletion(int $testId): void
    {
        $test = DB::table('title_ab_tests')->where('id', $testId)->first();
        if (! $test || $test->status !== 'running') {
            return;
        }

        // بررسی حداقل نمایش
        $totalImpressions = DB::table('title_ab_variants')
            ->where('test_id', $testId)
            ->sum('impressions');

        if ($totalImpressions < $test->min_impressions) {
            return;
        }

        // بررسی مدت زمان
        if ($test->started_at && now()->diffInDays($test->started_at) < $test->duration_days) {
            return;
        }

        // محاسبه اطمینان آماری
        $result = $this->calculateSignificance($testId);

        if ($result['confidence'] >= self::MIN_CONFIDENCE && $result['winner_id']) {
            // تعیین برنده
            DB::table('title_ab_tests')
                ->where('id', $testId)
                ->update([
                    'status' => 'completed',
                    'ended_at' => now(),
                    'winner_variant_id' => $result['winner_id'],
                    'updated_at' => now(),
                ]);

            DB::table('title_ab_variants')
                ->where('id', $result['winner_id'])
                ->update(['is_winner' => true]);

            // بروزرسانی خودکار در وردپرس (اگر فعال باشه)
            if (! empty($test->auto_update) && $test->auto_update && ! empty($test->wordpress_post_id)) {
                $publisher = app(AbTestPublisher::class);
                $publisher->applyWinner(
                    testId: $testId,
                    variantId: $result['winner_id'],
                    postId: (int) $test->wordpress_post_id
                );
            }
        }
    }

    /**
     * محاسبه اطمینان آماری بین دو variant.
     *
     * از Z-test برای مقایسه نسبت‌ها (CTR) استفاده میکنه.
     */
    public function calculateSignificance(int $testId): array
    {
        $variants = DB::table('title_ab_variants')
            ->where('test_id', $testId)
            ->orderBy('is_original', 'desc')
            ->get();

        if ($variants->count() < 2) {
            return ['confidence' => 0, 'winner_id' => null, 'details' => []];
        }

        // پیدا کردن بهترین variant (بیشترین CTR)
        $best = $variants->sortByDesc('ctr')->first();
        $original = $variants->firstWhere('is_original', true);

        if (! $original || $original->impressions < 10) {
            return ['confidence' => 0, 'winner_id' => null, 'details' => []];
        }

        // Z-test برای مقایسه CTR
        $confidence = $this->zTestCtr(
            $original->clicks, $original->impressions,
            $best->clicks, $best->impressions
        );

        // اگر بهترین، اصلی نیست و اطمینان کافیه
        $winnerId = null;
        if ($confidence >= self::MIN_CONFIDENCE && ! $best->is_original) {
            $winnerId = $best->id;
        }

        $details = [];
        foreach ($variants as $v) {
            $details[] = [
                'id' => $v->id,
                'title' => $v->title,
                'impressions' => $v->impressions,
                'clicks' => $v->clicks,
                'ctr' => round((float) $v->ctr, 2),
                'is_original' => (bool) $v->is_original,
                'is_winner' => (bool) $v->is_winner,
            ];
        }

        return [
            'confidence' => round((float) $confidence, 2),
            'winner_id' => $winnerId,
            'details' => $details,
        ];
    }

    /**
     * Z-test برای مقایسه دو نسبت (CTR).
     */
    private function zTestCtr(int $clicks1, int $impressions1, int $clicks2, int $impressions2): float
    {
        if ($impressions1 < 10 || $impressions2 < 10) {
            return 0;
        }

        $p1 = $clicks1 / $impressions1;
        $p2 = $clicks2 / $impressions2;
        $pPool = ($clicks1 + $clicks2) / ($impressions1 + $impressions2);

        $se = sqrt($pPool * (1 - $pPool) * (1/$impressions1 + 1/$impressions2));

        if ($se == 0) {
            return 0;
        }

        $z = abs($p1 - $p2) / $se;

        // تبدیل Z به درصد اطمینان (تقریبی)
        // استفاده از تابع CDF تقریبی
        $confidence = $this->zToConfidence($z);

        return $confidence;
    }

    /**
     * تبدیل Z-score به درصد اطمینان.
     */
    private function zToConfidence(float $z): float
    {
        // تقریب تابع CDF گاوسی
        $a1 = 0.254829592;
        $a2 = -0.284496736;
        $a3 = 1.421413741;
        $a4 = -1.453152027;
        $a5 = 1.061405429;
        $p = 0.3275911;

        $sign = $z < 0 ? -1 : 1;
        $z = abs($z) / sqrt(2);

        $t = 1.0 / (1.0 + $p * $z);
        $y = 1.0 - (((((($a5 * $t + $a4) * $t) + $a3) * $t + $a2) * $t + $a1) * $t * exp(-$z * $z));

        $cdf = 0.5 * (1.0 + $sign * $y);

        return $cdf * 100;
    }

    /**
     * دریافت اطلاعات یک تست.
     */
    public function getTest(int $testId): ?array
    {
        $test = DB::table('title_ab_tests')->where('id', $testId)->first();
        if (! $test) {
            return null;
        }

        $variants = DB::table('title_ab_variants')
            ->where('test_id', $testId)
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'title' => $v->title,
                'impressions' => $v->impressions,
                'clicks' => $v->clicks,
                'ctr' => round((float) $v->ctr, 2),
                'confidence' => round((float) $v->confidence, 2),
                'is_original' => (bool) $v->is_original,
                'is_winner' => (bool) $v->is_winner,
            ]);

        $significance = $this->calculateSignificance($testId);

        return [
            'id' => $test->id,
            'site_id' => $test->site_id,
            'draft_id' => $test->draft_id,
            'original_title' => $test->original_title,
            'status' => $test->status,
            'min_impressions' => $test->min_impressions,
            'duration_days' => $test->duration_days,
            'auto_update' => (bool) ($test->auto_update ?? false),
            'wordpress_post_id' => $test->wordpress_post_id,
            'started_at' => $test->started_at,
            'ended_at' => $test->ended_at,
            'winner_variant_id' => $test->winner_variant_id,
            'variants' => $variants,
            'significance' => $significance,
        ];
    }

    /**
     * لیست تست‌های یک سایت.
     */
    public function listTests(int $siteId, string $status = null): array
    {
        $query = DB::table('title_ab_tests')->where('site_id', $siteId);

        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('created_at')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'original_title' => $t->original_title,
                'status' => $t->status,
                'variants_count' => DB::table('title_ab_variants')->where('test_id', $t->id)->count(),
                'started_at' => $t->started_at,
                'ended_at' => $t->ended_at,
                'winner_variant_id' => $t->winner_variant_id,
                'created_at' => $t->created_at,
            ])->toArray();
    }

    /**
     * خلاصه وضعیت تست‌ها.
     */
    public function getSummary(int $siteId): array
    {
        $total = DB::table('title_ab_tests')->where('site_id', $siteId)->count();
        $running = DB::table('title_ab_tests')->where('site_id', $siteId)->where('status', 'running')->count();
        $completed = DB::table('title_ab_tests')->where('site_id', $siteId)->where('status', 'completed')->count();

        // میانگین بهبود CTR
        $completedTests = DB::table('title_ab_tests')
            ->where('site_id', $siteId)
            ->where('status', 'completed')
            ->whereNotNull('winner_variant_id')
            ->get();

        $avgImprovement = 0;
        if ($completedTests->count() > 0) {
            $improvements = [];
            foreach ($completedTests as $test) {
                $original = DB::table('title_ab_variants')->where('test_id', $test->id)->where('is_original', true)->first();
                $winner = DB::table('title_ab_variants')->where('id', $test->winner_variant_id)->first();

                if ($original && $winner && $original->impressions > 0) {
                    $originalCtr = ($original->clicks / $original->impressions) * 100;
                    $winnerCtr = ($winner->clicks / $winner->impressions) * 100;
                    if ($originalCtr > 0) {
                        $improvements[] = (($winnerCtr - $originalCtr) / $originalCtr) * 100;
                    }
                }
            }
            if (count($improvements) > 0) {
                $avgImprovement = round(array_sum($improvements) / count($improvements), 1);
            }
        }

        return [
            'total_tests' => $total,
            'running' => $running,
            'completed' => $completed,
            'avg_ctr_improvement' => $avgImprovement,
        ];
    }
}
