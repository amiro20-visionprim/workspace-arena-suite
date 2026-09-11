<?php

declare(strict_types=1);

namespace App\Domains\Automation\Services;

use Illuminate\Support\Facades\DB;

/**
 * قدم ۳ اکوسیستم — شبیه‌ساز پیش از اجرا (Pre-Execution Simulator).
 *
 * قبل از اینکه PolicyEvaluator تصمیم بگیرد، اثر مورد انتظار هر تغییر را
 * تخمین می‌زند — از دادهٔ تاریخی impact_events + نرخ موفقیت یادگیری +
 * ترافیک واقعی صفحهٔ هدف (DataBridge). خروجی:
 *
 *  - expected_impact: عدد ۰–۱۰۰ (اثر تخمینی بر ترافیک/رتبه)
 *  - success_probability: احتمال موفقیت بر اساس سابقهٔ همان نوع
 *  - risk_tier: ریسک ساختاریِ پیشنهادی برای evaluator
 *  - simulated_confidence: امتیاز اطمینانِ پیشنهادی برای evaluator
 *
 * این شبیه‌ساز «واکنشی‌بودن» سیستم را به «پیش‌بین‌بودن» تبدیل می‌کند:
 * تصمیم‌گیرنده پیش از اجرا می‌داند هر تغییر چقدر ارزش دارد و چقدر
 * احتمال شکست دارد.
 */
class PreExecutionSimulator
{
    /** پنجرهٔ دادهٔ تاریخی برای تخمین (روز) */
    public const HISTORY_WINDOW_DAYS = 90;

    /** آستانهٔ اثر — زیر آن پیشنهادِ «اجرا نکن» داده می‌شود مگر تأیید انسانی */
    public const MIN_EXPECTED_IMPACT = 25;

    public function __construct(
        private readonly ConfidenceScorer $confidenceScorer,
        private readonly AdaptiveLearning $adaptiveLearning,
    ) {}

    /**
     * شبیه‌سازی اثر یک تغییر پیش از اجرا.
     *
     * @param  int  $siteId
     * @param  string  $commandType  مثلاً publish_new_article | update_meta_title | update_content
     * @param  array<string, mixed>  $context  {url?, keyword?, content_type?, quality_score?}
     * @return array<string, mixed>
     */
    public function simulate(int $siteId, string $commandType, array $context = []): array
    {
        $contentType = (string) ($context['content_type'] ?? $this->contentTypeFor($commandType));
        $url = (string) ($context['url'] ?? '');

        // ۱) اثر تاریخی همان نوع (impact_events — بعد از انتشار/اجرا ثبت می‌شوند)
        $history = $this->historicalImpact($siteId, $commandType);

        // ۲) نرخ موفقیت از حلقهٔ یادگیری
        $learning = $this->adaptiveLearning->health($siteId, $commandType);
        $successRate = $learning['success_rate'] ?? null;

        // ۳) ترافیک واقعی صفحهٔ هدف (DataBridge)
        $trafficFactor = $this->trafficFactor($siteId, $url);

        // ۴) کیفیت محتوای تولیدشده (اگر از گیت عبور کرده باشد)
        $quality = (float) ($context['quality_score'] ?? 70) / 100;

        // ── ترکیب ──
        // اثر = ۴۰٪ سابقه + ۳۰٪ ترافیک + ۳۰٪ کیفیت
        $impact = 0.4 * $history + 0.3 * $trafficFactor + 0.3 * $quality;
        $expectedImpact = (int) round($impact * 100);

        // احتمال موفقیت: سابقهٔ یادگیری (پیش‌فرض ۰.۵ بدون داده) تعدیل‌شده با کیفیت
        $successProbability = (float) ($successRate ?? 0.5);
        $successProbability = $successProbability * (0.7 + 0.3 * $quality);
        $successProbability = (float) max(0.0, min(1.0, $successProbability));

        // ریسک ساختاری
        $riskTier = $this->riskTierFor($commandType, $successProbability);

        // اطمینان پیشنهادی — از ConfidenceScorer با دادهٔ واقعی
        $confidence = $this->confidenceScorer->score([
            'data_quality' => $trafficFactor,
            'signal_strength' => $history,
            'sources' => $learning['total'] > 0 ? ['rule_based', 'human_review'] : ['rule_based'],
            'history' => ['total' => $learning['total'], 'successful' => (int) $learning['successful']],
        ]);

        $recommendation = $expectedImpact >= self::MIN_EXPECTED_IMPACT ? 'execute' : 'review';

        return [
            'expected_impact' => $expectedImpact,
            'success_probability' => round($successProbability, 3),
            'risk_tier' => $riskTier,
            'simulated_confidence' => $confidence['score'],
            'confidence_factors' => $confidence['factors'],
            'recommendation' => $recommendation,
            'reason' => $this->reason($expectedImpact, $successProbability, $learning['total']),
            'traffic_factor' => round($trafficFactor, 3),
            'history' => $history,
        ];
    }

    /**
     * اثر تاریخی همان نوع تغییر: میانگین بهبود مشاهده‌شده در impact_events (۰–۱).
     */
    private function historicalImpact(int $siteId, string $commandType): float
    {
        $since = now()->subDays(self::HISTORY_WINDOW_DAYS);

        $events = DB::table('impact_events')
            ->where('site_id', $siteId)
            ->where('source_type', $commandType)
            ->where('observed_at', '>=', $since)
            ->get(['outcome']);

        if ($events->isEmpty()) {
            // بدون سابقه — بر اساس نوع، تخمین محافظه‌کارانه
            return match ($commandType) {
                'publish_new_article' => 0.5,
                'update_meta_title' => 0.55,
                'update_content' => 0.45,
                default => 0.4,
            };
        }

        $sum = 0.0;
        $count = 0;
        foreach ($events as $event) {
            $outcome = json_decode((string) $event->outcome, true);
            if (! is_array($outcome)) {
                continue;
            }
            // متریک‌های رایج: clicks_delta, position_delta, views_delta
            foreach (['clicks_delta', 'views_delta'] as $metric) {
                $delta = (float) ($outcome[$metric] ?? 0);
                if ($delta > 0) {
                    $sum += min(1.0, $delta / 10); // ۱۰+ کلیک = ۱.۰
                    $count++;
                }
            }
        }

        if ($count === 0) {
            return 0.4;
        }

        return (float) max(0.0, min(1.0, $sum / $count));
    }

    /**
     * فاکتور ترافیک صفحهٔ هدف (۰–۱) از DataBridge؛ بدون URL → میانگین سایت.
     */
    private function trafficFactor(int $siteId, string $url): float
    {
        $since = now()->subDays(30);

        if ($url !== '') {
            $views = (int) DB::table('site_traffic_daily')
                ->where('site_id', $siteId)
                ->where('page_url', $this->normalizeUrl($url))
                ->where('date', '>=', $since->toDateString())
                ->sum('views');

            return $this->trafficToFactor($views);
        }

        $avg = (float) DB::table('site_traffic_daily')
            ->where('site_id', $siteId)
            ->where('date', '>=', $since->toDateString())
            ->avg('views');

        return $this->trafficToFactor($avg);
    }

    private function trafficToFactor(float $views): float
    {
        if ($views <= 0) {
            return 0.0;
        }

        return (float) min(1.0, log10(1 + $views) / 3);
    }

    private function normalizeUrl(string $url): string
    {
        $url = preg_replace('#^https?://#i', '', $url);
        $url = preg_replace('#^www\.#i', '', $url);

        return rtrim((string) $url, '/');
    }

    /** ریسک ساختاری بر اساس نوع تغییر و احتمال موفقیت. */
    private function riskTierFor(string $commandType, float $successProbability): string
    {
        // محتوای جدید: همیشه حداقل R2
        if (in_array($commandType, ['publish_new_article', 'update_published_content'], true)) {
            return $successProbability >= 0.8 ? 'R2' : 'R3';
        }

        // متا: کم‌خطر
        if (str_starts_with($commandType, 'update_meta_')) {
            return $successProbability >= 0.7 ? 'R1' : 'R2';
        }

        return $successProbability >= 0.7 ? 'R1' : 'R2';
    }

    private function contentTypeFor(string $commandType): string
    {
        return match (true) {
            str_starts_with($commandType, 'update_meta_') => 'meta',
            in_array($commandType, ['publish_new_article', 'update_published_content'], true) => 'article',
            default => 'article',
        };
    }

    private function reason(int $expectedImpact, float $successProbability, int $samples): string
    {
        if ($expectedImpact < self::MIN_EXPECTED_IMPACT) {
            return 'اثر مورد انتظار پایین است؛ پیشنهاد بازبینی انسانی قبل از اجرا.';
        }

        if ($successProbability >= 0.8) {
            return 'سابقهٔ موفق بالا + اثر مورد انتظار خوب؛ آمادهٔ اجرای خودکار.';
        }

        return 'اثر مورد انتظار قابل قبول است؛ به دلیل سابقهٔ محدود، اجرا با احتیاط.';
    }
}