<?php

declare(strict_types=1);

namespace App\Domains\Automation\Services;

use Illuminate\Support\Facades\DB;

/**
 * قدم ۲ اکوسیستم — DecisionEngine (موتور تخصیص بودجه هوشمند).
 *
 * جایگزین مرتب‌سازی سادهٔ `sortByDesc('score')` در OpportunityPipeline:
 * هر فرصت را با «اثر مورد انتظار ÷ ریسک» رتبه‌بندی می‌کند و با در نظر
 * گرفتن بودجهٔ روزانه (سقف اجرای سایت) بهترین ترکیب را تخصیص می‌دهد.
 *
 * امتیاز اثر (expected_impact) از ۴ منبع ساخته می‌شود:
 *  - score فرصت (قدرت سیگنال از کرالر/تحلیل)
 *  - confidence فرصت (اتفاق‌نظر منابع)
 *  - نرخ موفقیت تاریخی همان نوع تغییر (automation_learning_history)
 *  - ترافیک واقعی صفحهٔ هدف (site_traffic_daily از DataBridge — بدون GSC هم کار می‌کند)
 *
 * ریسک هر فرصت از نوع تغییر و سابقهٔ شکست همان نوع ساخته می‌شود؛
 * فرصت‌های نوعِ مسدودشده توسط AdaptiveLearning هرگز تخصیص نمی‌یابند.
 */
class DecisionEngine
{
    /** وزن انواع فرصت — اثر مورد انتظار نسبی هر نوع تغییر (۰–۱) */
    private const TYPE_IMPACT = [
        'keyword_opportunity' => 1.0,    // تولید محتوای جدید با هدف کیوورد
        'conversion_boost' => 1.0,       // بهبود تبدیل
        'ctr_gap' => 0.9,                // شکاف CTR (عنوان/توضیح)
        'content_gap' => 0.8,            // پر کردن شکاف محتوایی
        'meta_optimization' => 0.75,     // بهینه‌سازی متادیتا
        'interlink_opportunity' => 0.6,  // لینک داخلی
        'schema_gap' => 0.5,             // اسکیما
        'accessibility' => 0.3,          // دسترسی‌پذیری
    ];

    /** ریسک پایهٔ هر نوع (۰–۱) — هرچه بالاتر، احتمال برگشت/خطا بیشتر */
    private const TYPE_RISK = [
        'keyword_opportunity' => 0.55,   // محتوای جدید: پرهزینه‌ترین
        'conversion_boost' => 0.5,
        'ctr_gap' => 0.2,                // متا: کم‌خطر
        'content_gap' => 0.45,
        'meta_optimization' => 0.15,
        'interlink_opportunity' => 0.25,
        'schema_gap' => 0.2,
        'accessibility' => 0.1,
    ];

    public function __construct(
        private readonly AdaptiveLearning $adaptiveLearning,
    ) {}

    /**
     * رتبه‌بندی و تخصیص فرصت‌ها برای یک سایت.
     *
     * @param  int  $siteId
     * @param  array<int, object>  $opportunities  ردیف‌های raw از opportunities/static_opportunities
     * @param  int  $dailyBudget  سقف اجرای روزانه (از پروفایل خودمختاری سایت)
     * @return array{
     *     allocated: array<int, array<string, mixed>>,
     *     deferred: array<int, array<string, mixed>>,
     *     budget: int,
     *     used: int,
     *     method: string
     * }
     */
    public function allocate(int $siteId, array $opportunities, int $dailyBudget = 10): array
    {
        $scored = [];
        foreach ($opportunities as $opp) {
            $type = (string) ($opp->type ?? '');
            if ($type === '') {
                continue;
            }

            // ۱) فرصت‌های نوعِ مسدودشده توسط حلقهٔ یادگیری: هرگز تخصیص
            if ($this->adaptiveLearning->isBlocked($siteId, $this->commandTypeFor($type))) {
                continue;
            }

            $impact = $this->expectedImpact($siteId, $opp);
            $risk = $this->riskScore($siteId, $type);
            $rank = $risk > 0 ? round($impact / $risk, 4) : $impact;

            $scored[] = [
                'opportunity' => $opp,
                'expected_impact' => round($impact, 3),
                'risk' => round($risk, 3),
                'rank' => $rank,
                'cost' => $this->costFor($type),
            ];
        }

        // ۲) مرتب‌سازی نزولی بر اساس اثر÷ریسک
        usort($scored, fn (array $a, array $b): int => $b['rank'] <=> $a['rank']);

        // ۳) تخصیص حریصانه با بودجهٔ روزانه
        $allocated = [];
        $deferred = [];
        $used = 0;
        foreach ($scored as $row) {
            if ($used + $row['cost'] > $dailyBudget) {
                $deferred[] = $row;

                continue;
            }
            $used += $row['cost'];
            $allocated[] = $row;
        }

        return [
            'allocated' => $this->present($allocated),
            'deferred' => $this->present($deferred),
            'budget' => $dailyBudget,
            'used' => $used,
            'method' => 'impact_over_risk_greedy',
        ];
    }

    /**
     * تخمین اثر مورد انتظار یک فرصت (۰–۱).
     */
    private function expectedImpact(int $siteId, object $opp): float
    {
        $type = (string) $opp->type;
        $typeImpact = self::TYPE_IMPACT[$type] ?? 0.5;

        // قدرت سیگنال: score + confidence (هر دو ۰–۱)
        $score = (float) ($opp->score ?? 0) / 100;
        $confidence = (float) ($opp->confidence ?? 0);

        // نرخ موفقیت تاریخی همان نوع (خنثی در نبود سابقه)
        $history = $this->historyRate($siteId, $this->commandTypeFor($type));

        // ترافیک واقعی صفحهٔ هدف از DataBridge (اگر صفحه‌ای دارد)
        $traffic = $this->pageTraffic($siteId, $opp);

        // ترکیب وزنی: سیگنال ۵۰٪، سابقه ۲۰٪، ترافیک ۲۰٪، نوع ۱۰٪
        $impact = 0.5 * $score
            + 0.2 * $history
            + 0.2 * $traffic
            + 0.1 * $typeImpact;

        // اگر فرصت هم score بالا و هم confidence بالا داشته باشد، کمی تقویت می‌شود
        $agreement = $score * $confidence;
        $impact += 0.05 * $agreement;

        return max(0.0, min(1.0, $impact));
    }

    /**
     * ریسک یک فرصت (۰–۱) — نوع + سابقهٔ شکست همان نوع.
     */
    private function riskScore(int $siteId, string $type): float
    {
        $baseRisk = self::TYPE_RISK[$type] ?? 0.4;

        // سابقهٔ شکست: هرچه نرخ موفقیت پایین‌تر، ریسک بالاتر (حداکثر ۱.۵×)
        $history = $this->historyRate($siteId, $this->commandTypeFor($type));
        $failurePenalty = (1 - $history) * 0.5;

        return max(0.05, min(1.0, $baseRisk + $failurePenalty));
    }

    /**
     * هزینهٔ اجرای هر فرصت — محتوای جدید گران‌تر از متا/لینک.
     */
    private function costFor(string $type): int
    {
        return match ($type) {
            'keyword_opportunity', 'conversion_boost', 'content_gap' => 3,
            'ctr_gap', 'meta_optimization', 'schema_gap' => 1,
            default => 2,
        };
    }

    /**
     * نرخ موفقیت تاریخی نوع تغییر (۰–۱)، خنثی (۰.۵) در نبود سابقه.
     */
    private function historyRate(int $siteId, string $commandType): float
    {
        $row = DB::table('automation_learning_history')
            ->where('site_id', $siteId)
            ->where('command_type', $commandType)
            ->first();

        if ($row === null) {
            return 0.5;
        }

        $total = (int) $row->total;
        if ($total <= 0) {
            return 0.5;
        }

        return (float) $row->successful / $total;
    }

    /**
     * ترافیک واقعی صفحهٔ هدف از site_traffic_daily (۳۰ روز اخیر).
     *
     * اگر فرصت به صفحه‌ای وصل باشد (url_profile_id) بازدید آن صفحه،
     * وگرنه میانگین بازدید کل سایت به‌عنوان baseline.
     */
    private function pageTraffic(int $siteId, object $opp): float
    {
        $since = now()->subDays(30);

        if (! empty($opp->url_profile_id)) {
            $profile = DB::table('url_profiles')->where('id', $opp->url_profile_id)->first();
            $url = $profile->url ?? null;
            if ($url !== null) {
                $views = (int) DB::table('site_traffic_daily')
                    ->where('site_id', $siteId)
                    ->where('page_url', $this->normalizeUrl($url))
                    ->where('date', '>=', $since->toDateString())
                    ->sum('views');

                return $this->trafficToFactor($views);
            }
        }

        // baseline: میانگین بازدید روزانهٔ کل سایت
        $avg = (float) DB::table('site_traffic_daily')
            ->where('site_id', $siteId)
            ->where('date', '>=', $since->toDateString())
            ->avg('views');

        return $this->trafficToFactor($avg);
    }

    private function trafficToFactor(float $views): float
    {
        // ۱۰۰۰+ بازدید = ۱.۰، ۰ بازدید = ۰ (log-ish scale)
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

    /** نگاشت نوع فرصت به نوع command برای AdaptiveLearning/تاریخچه. */
    private function commandTypeFor(string $opportunityType): string
    {
        return match ($opportunityType) {
            'keyword_opportunity', 'conversion_boost', 'content_gap' => 'publish_new_article',
            'ctr_gap', 'meta_optimization' => 'update_meta_title',
            'interlink_opportunity' => 'update_content',
            default => 'update_content',
        };
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function present(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $opp = $row['opportunity'];
            $out[] = [
                'opportunity_id' => (int) $opp->id,
                'source' => property_exists($opp, 'source') && ! empty($opp->source) ? (string) $opp->source : 'analyzer',
                'type' => (string) $opp->type,
                'title' => property_exists($opp, 'title') ? (string) ($opp->title ?? '') : '',
                'keyword' => property_exists($opp, 'keyword_suggested') && ! empty($opp->keyword_suggested)
                    ? (string) $opp->keyword_suggested
                    : '',
                'expected_impact' => $row['expected_impact'],
                'risk' => $row['risk'],
                'rank' => round($row['rank'], 4),
                'cost' => $row['cost'],
            ];
        }

        return $out;
    }
}