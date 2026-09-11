<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CrawlerAnalysisController extends Controller
{
    /**
     * داشبورد تحلیل کرالر — خلاصه + فرصت‌ها + ریسک‌ها.
     */
    public function index(Request $request): JsonResponse
    {
        $siteId = $request->user()->currentSiteId ?? $request->input('site_id', 1);

        // خلاصه کلی
        $summary = $this->buildSummary($siteId);

        // فرصت‌ها با فیلتر
        $type = $request->input('type');
        $source = $request->input('source');
        $minScore = $request->input('min_score', 0);
        $search = $request->input('search');

        $opportunities = $this->getOpportunities($siteId, $type, $source, $minScore, $search);

        // ریسک‌ها
        $risks = $this->getRisks($siteId);

        // توزیع انواع
        $typeDistribution = $this->getTypeDistribution($siteId);

        // توزیع منابع
        $sourceDistribution = $this->getSourceDistribution($siteId);

        return response()->json([
            'summary' => $summary,
            'opportunities' => $opportunities,
            'risks' => $risks,
            'type_distribution' => $typeDistribution,
            'source_distribution' => $sourceDistribution,
        ]);
    }

    /**
     * خلاصه کلی تحلیل.
     */
    private function buildSummary(int $siteId): array
    {
        $totalOpps = DB::table('static_opportunities')->where('site_id', $siteId)->count();
        $openOpps = DB::table('static_opportunities')->where('site_id', $siteId)->where('status', 'open')->count();
        $processedOpps = DB::table('static_opportunities')->where('site_id', $siteId)->where('status', 'processed')->count();
        $totalRisks = DB::table('static_risk_patterns')->where('site_id', $siteId)->count();
        $openRisks = DB::table('static_risk_patterns')->where('site_id', $siteId)->where('status', 'open')->count();
        $avgScore = DB::table('static_opportunities')->where('site_id', $siteId)->where('status', 'open')->avg('score') ?? 0;

        $profiles = DB::table('url_profiles')->where('site_id', $siteId)->count();
        $lastCrawl = DB::table('url_profiles')->where('site_id', $siteId)->max('updated_at');

        return [
            'total_opportunities' => $totalOpps,
            'open_opportunities' => $openOpps,
            'processed_opportunities' => $processedOpps,
            'total_risks' => $totalRisks,
            'open_risks' => $openRisks,
            'avg_score' => round((float) $avgScore, 1),
            'total_profiles' => $profiles,
            'last_crawl' => $lastCrawl,
        ];
    }

    /**
     * لیست فرصت‌ها با فیلتر و صفحه‌بندی.
     */
    private function getOpportunities(int $siteId, ?string $type, ?string $source, int $minScore, ?string $search): array
    {
        $query = DB::table('static_opportunities')
            ->where('site_id', $siteId)
            ->where('score', '>=', $minScore);

        if ($type !== null) {
            $query->where('type', $type);
        }
        if ($source !== null) {
            $query->where('source', $source);
        }
        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ILIKE', "%{$search}%")
                    ->orWhere('explanation', 'ILIKE', "%{$search}%")
                    ->orWhere('keyword_suggested', 'ILIKE', "%{$search}%");
            });
        }

        $total = (clone $query)->count();

        $items = $query->orderByDesc('score')
            ->limit(50)
            ->get()
            ->map(function ($item) {
                $meta = json_decode($item->metadata, true) ?? [];
                return [
                    'id' => $item->id,
                    'type' => $item->type,
                    'source' => $item->source,
                    'title' => $item->title,
                    'keyword_suggested' => $item->keyword_suggested,
                    'score' => $item->score,
                    'confidence' => $item->confidence,
                    'status' => $item->status,
                    'explanation' => $item->explanation,
                    'url' => $meta['url'] ?? null,
                    'depth' => $meta['depth'] ?? null,
                    'days_since_update' => $meta['days_since_update'] ?? null,
                    'density' => $meta['density'] ?? null,
                ];
            });

        return ['total' => $total, 'items' => $items];
    }

    /**
     * لیست ریسک‌ها.
     */
    private function getRisks(int $siteId): array
    {
        return DB::table('static_risk_patterns')
            ->where('site_id', $siteId)
            ->where('status', 'open')
            ->orderByDesc('severity')
            ->get()
            ->map(function ($r) {
                $meta = json_decode($r->metadata, true) ?? [];
                return [
                    'id' => $r->id,
                    'key' => $r->key,
                    'severity' => $r->severity,
                    'explanation' => $r->explanation,
                    'url' => $meta['url'] ?? null,
                ];
            })->toArray();
    }

    /**
     * توزیع انواع فرصت.
     */
    private function getTypeDistribution(int $siteId): array
    {
        return DB::table('static_opportunities')
            ->where('site_id', $siteId)
            ->select('type', DB::raw('count(*) as cnt'))
            ->groupBy('type')
            ->pluck('cnt', 'type')
            ->toArray();
    }

    /**
     * توزیع منابع.
     */
    private function getSourceDistribution(int $siteId): array
    {
        return DB::table('static_opportunities')
            ->where('site_id', $siteId)
            ->select('source', DB::raw('count(*) as cnt'))
            ->groupBy('source')
            ->pluck('cnt', 'source')
            ->toArray();
    }

    /**
     * تغییر وضعیت فرصت (بستن/سرکوب کردن).
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $request->validate(['status' => 'required|in:open,closed,suppressed']);

        DB::table('static_opportunities')
            ->where('id', $id)
            ->update(['status' => $request->status, 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * تبدیل فرصت به دسته تولید گروهی.
     */
    public function createBulkJob(Request $request, int $id): JsonResponse
    {
        $opp = DB::table('static_opportunities')->where('id', $id)->first();
        if (! $opp) {
            return response()->json(['error' => 'Opportunity not found'], 404);
        }

        $siteId = $opp->site_id;
        $keyword = $opp->keyword_suggested ?? $opp->title;
        if (empty($keyword)) {
            return response()->json(['error' => 'No keyword to create job from'], 422);
        }

        // ساخت دسته جدید
        $jobId = (int) DB::table('bulk_jobs')->insertGetId([
            'organization_id' => (int) DB::table('sites')->where('id', $siteId)->value('organization_id'),
            'site_id' => $siteId,
            'name' => "فرصت کرالر: {$keyword}",
            'content_type' => 'article',
            'subtype' => 'article',
            'status' => 'pending',
            'auto_publish' => 'off',
            'created_by' => $request->user()->id ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bulk_job_items')->insert([
            'bulk_job_id' => $jobId,
            'keyword' => $keyword,
            'title' => $keyword,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // بستن فرصت
        DB::table('static_opportunities')
            ->where('id', $id)
            ->update(['status' => 'processed', 'updated_at' => now()]);

        return response()->json(['ok' => true, 'job_id' => $jobId]);
    }
}
