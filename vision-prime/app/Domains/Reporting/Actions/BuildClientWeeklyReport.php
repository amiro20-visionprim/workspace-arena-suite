<?php

declare(strict_types=1);

namespace App\Domains\Reporting\Actions;

use App\Domains\Workspace\Models\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * قدم ۵ اکوسیستم — گزارش هفتگی خودکار مشتری.
 *
 * برای هر مشتری، داده‌های زیر را در بازهٔ هفتگی جمع‌آوری می‌کند:
 *  - تولیدات محتوا (تعداد + کیفیت میانگین)
 *  - انتشارها (موفق/ناموفق)
 *  - فرصت‌های شناسایی‌شده و اقدام‌شده
 *  - ریسک‌های تبدیل
 *  - تغییرات GSC (کلیک/نمایش/جایگاه)
 *  - فعالیت‌های اتوماسیون
 *
 * خروجی: آرایهٔ JSON-سازگار که در جدول `reports` ذخیره می‌شود.
 */
class BuildClientWeeklyReport
{
    /**
     * @return array{report_id: int, summary: array<string, mixed>}
     */
    public function handle(Client $client, ?Carbon $weekStart = null, ?Carbon $weekEnd = null): array
    {
        $weekStart ??= now()->startOfWeek(Carbon::SATURDAY)->subWeek();
        $weekEnd ??= $weekStart->copy()->addDays(6);

        $siteIds = $this->clientSiteIds($client);

        $summary = [
            'client' => $client->name,
            'period' => [
                'start' => $weekStart->toDateString(),
                'end' => $weekEnd->toDateString(),
                'label' => $weekStart->format('Y/m/d').' تا '.$weekEnd->format('Y/m/d'),
            ],
            'content' => $this->contentStats($siteIds, $weekStart, $weekEnd),
            'publishing' => $this->publishStats($siteIds, $weekStart, $weekEnd),
            'opportunities' => $this->opportunityStats($siteIds, $weekStart, $weekEnd),
            'risks' => $this->riskStats($siteIds),
            'seo_trend' => $this->seoTrend($siteIds, $weekStart, $weekEnd),
            'automation' => $this->automationStats($siteIds, $weekStart, $weekEnd),
            'highlights' => [],
        ];

        // نقاط عطف هفته
        $summary['highlights'] = $this->deriveHighlights($summary);

        // ذخیره در reports
        $reportId = (int) DB::table('reports')->insertGetId([
            'site_id' => $siteIds->first() ?? 0,
            'type' => 'client_weekly',
            'period_start' => $weekStart->toDateString(),
            'period_end' => $weekEnd->toDateString(),
            'status' => 'draft',
            'content' => json_encode($summary, JSON_UNESCAPED_UNICODE),
            'generated_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['report_id' => $reportId, 'summary' => $summary];
    }

    private function clientSiteIds(Client $client): \Illuminate\Support\Collection
    {
        return DB::table('sites')
            ->join('projects', 'projects.id', '=', 'sites.project_id')
            ->where('projects.client_id', $client->getKey())
            ->pluck('sites.id');
    }

    private function contentStats(\Illuminate\Support\Collection $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds->isEmpty()) {
            return ['drafts_created' => 0, 'avg_quality' => 0, 'total_words' => 0];
        }

        $drafts = DB::table('content_drafts')
            ->whereIn('site_id', $siteIds)
            ->whereBetween('created_at', [$start->toDateTime(), $end->copy()->endOfDay()->toDateTime()])
            ->get();

        return [
            'drafts_created' => $drafts->count(),
            'avg_quality' => $drafts->count() > 0 ? round($drafts->avg('quality_score'), 1) : 0,
            'total_words' => (int) $drafts->sum(fn ($d) => preg_match_all('/[\p{L}\p{N}]+/u', $d->content ?? '')),
            'rule_based_pct' => $drafts->count() > 0
                ? round($drafts->where('model_used', 'rule-based')->count() / $drafts->count() * 100)
                : 0,
        ];
    }

    private function publishStats(\Illuminate\Support\Collection $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds->isEmpty()) {
            return ['published' => 0, 'failed' => 0, 'pending' => 0];
        }

        $items = DB::table('bulk_job_items')
            ->join('bulk_jobs', 'bulk_jobs.id', '=', 'bulk_job_items.bulk_job_id')
            ->whereIn('bulk_jobs.site_id', $siteIds)
            ->whereNotNull('bulk_job_items.publish_status')
            ->whereBetween('bulk_job_items.updated_at', [$start->toDateTime(), $end->copy()->endOfDay()->toDateTime()])
            ->get(['bulk_job_items.publish_status']);

        return [
            'published' => $items->where('publish_status', 'published')->count(),
            'failed' => $items->where('publish_status', 'failed')->count(),
            'pending' => $items->where('publish_status', 'publishing')->count(),
        ];
    }

    private function opportunityStats(\Illuminate\Support\Collection $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds->isEmpty()) {
            return ['found' => 0, 'acted' => 0, 'types' => [], 'crawler_opps' => 0, 'crawler_risks' => 0];
        }

        // فرصت‌های جدول اصلی
        $all = DB::table('opportunities')
            ->whereIn('site_id', $siteIds)
            ->count();

        $acted = DB::table('opportunities')
            ->whereIn('site_id', $siteIds)
            ->where('status', 'processed')
            ->whereBetween('updated_at', [$start->toDateTime(), $end->copy()->endOfDay()->toDateTime()])
            ->count();

        $types = DB::table('opportunities')
            ->whereIn('site_id', $siteIds)
            ->select('type', DB::raw('count(*) as cnt'))
            ->groupBy('type')
            ->pluck('cnt', 'type')
            ->toArray();

        // فرصت‌های کرالر (static_opportunities)
        $crawlerOpps = 0;
        $crawlerRisks = 0;
        if (\Illuminate\Support\Facades\Schema::hasTable('static_opportunities')) {
            $crawlerOpps = DB::table('static_opportunities')
                ->whereIn('site_id', $siteIds)
                ->count();

            // انواع فرصت‌های کرالر
            $crawlerTypes = DB::table('static_opportunities')
                ->whereIn('site_id', $siteIds)
                ->select('type', DB::raw('count(*) as cnt'))
                ->groupBy('type')
                ->pluck('cnt', 'type')
                ->toArray();
            $types = array_merge($types, $crawlerTypes);
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('static_risk_patterns')) {
            $crawlerRisks = DB::table('static_risk_patterns')
                ->whereIn('site_id', $siteIds)
                ->where('status', 'open')
                ->count();
        }

        return [
            'found' => $all + $crawlerOpps,
            'acted' => $acted,
            'types' => $types,
            'crawler_opps' => $crawlerOpps,
            'crawler_risks' => $crawlerRisks,
        ];
    }

    private function riskStats(\Illuminate\Support\Collection $siteIds): array
    {
        if ($siteIds->isEmpty()) {
            return ['total' => 0, 'high' => 0, 'resolved' => 0, 'crawler_risks' => 0];
        }

        $risks = DB::table('conversion_risks')
            ->join('url_profiles', 'url_profiles.id', '=', 'conversion_risks.url_profile_id')
            ->whereIn('url_profiles.site_id', $siteIds);

        $total = (clone $risks)->count();
        $high = (clone $risks)->where('conversion_risks.severity', 'high')->count();

        // ریسک‌های کرالر
        $crawlerRisks = 0;
        $crawlerHigh = 0;
        if (\Illuminate\Support\Facades\Schema::hasTable('static_risk_patterns')) {
            $crawlerRisks = DB::table('static_risk_patterns')
                ->whereIn('site_id', $siteIds)
                ->where('status', 'open')
                ->count();
            $crawlerHigh = DB::table('static_risk_patterns')
                ->whereIn('site_id', $siteIds)
                ->where('status', 'open')
                ->where('severity', 'high')
                ->count();
        }

        return [
            'total' => $total + $crawlerRisks,
            'high' => $high + $crawlerHigh,
            'resolved' => 0,
            'crawler_risks' => $crawlerRisks,
        ];
    }

    private function seoTrend(\Illuminate\Support\Collection $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds->isEmpty()) {
            return ['clicks_delta' => 0, 'impressions_delta' => 0, 'avg_position_delta' => 0];
        }

        $propertyIds = DB::table('gsc_properties')
            ->whereIn('site_id', $siteIds)
            ->where('status', 'selected')
            ->pluck('id');

        if ($propertyIds->isEmpty()) {
            return ['clicks_delta' => 0, 'impressions_delta' => 0, 'avg_position_delta' => 0, 'note' => 'GSC متصل نیست'];
        }

        $before = DB::table('gsc_site_metrics')
            ->whereIn('gsc_property_id', $propertyIds)
            ->whereBetween('date', [$start->copy()->subWeek()->toDateString(), $start->copy()->subDay()->toDateString()])
            ->first([DB::raw('SUM(clicks) as clicks'), DB::raw('SUM(impressions) as impressions')]);

        $after = DB::table('gsc_site_metrics')
            ->whereIn('gsc_property_id', $propertyIds)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->first([DB::raw('SUM(clicks) as clicks'), DB::raw('SUM(impressions) as impressions')]);

        return [
            'clicks_delta' => ((int) ($after->clicks ?? 0)) - ((int) ($before->clicks ?? 0)),
            'impressions_delta' => ((int) ($after->impressions ?? 0)) - ((int) ($before->impressions ?? 0)),
        ];
    }

    private function automationStats(\Illuminate\Support\Collection $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds->isEmpty()) {
            return ['commands_executed' => 0, 'auto_published' => 0, 'human_approved' => 0];
        }

        $commands = DB::table('commands')
            ->whereIn('site_id', $siteIds)
            ->whereBetween('updated_at', [$start->toDateTime(), $end->copy()->endOfDay()->toDateTime()]);

        return [
            'commands_executed' => (clone $commands)->where('status', 'executed')->count(),
            'auto_published' => (clone $commands)->where('status', 'executed')->where('decision_source', 'auto')->count(),
            'human_approved' => (clone $commands)->where('status', 'executed')->where('decision_source', 'human')->count(),
        ];
    }

    private function deriveHighlights(array $summary): array
    {
        $highlights = [];

        if ($summary['content']['drafts_created'] > 0) {
            $highlights[] = "{$summary['content']['drafts_created']} محتوای جدید تولید شد (کیفیت میانگین: {$summary['content']['avg_quality']}/۱۰۰)";
        }

        if ($summary['publishing']['published'] > 0) {
            $highlights[] = "{$summary['publishing']['published']} محتوا با موفقیت منتشر شد";
        }

        if ($summary['opportunities']['acted'] > 0) {
            $highlights[] = "{$summary['opportunities']['acted']} فرصت شناسایی‌شده اقدام شد";
        }

        if ($summary['seo_trend']['clicks_delta'] > 0) {
            $highlights[] = "کلیک‌ها +{$summary['seo_trend']['clicks_delta']} نسبت به هفته قبل";
        }

        if ($summary['risks']['high'] > 0) {
            $highlights[] = "{$summary['risks']['high']} ریسک بالا شناسایی شد که نیاز به توجه دارد";
        }

        // بینش‌های کرالر
        if (($summary['opportunities']['crawler_opps'] ?? 0) > 0) {
            $highlights[] = "کرالر هوشمند {$summary['opportunities']['crawler_opps']} فرصت بهینه‌سازی شناسایی کرد";
        }
        if (($summary['opportunities']['crawler_risks'] ?? 0) > 0) {
            $highlights[] = "{$summary['opportunities']['crawler_risks']} مشکل کیفیت صفحات توسط کرالر کشف شد";
        }

        if (empty($highlights)) {
            $highlights[] = "هفته آرامی بود — سیستم در حال پایش و آماده‌سازی فرصت‌ها";
        }

        return $highlights;
    }
}
