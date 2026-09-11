<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Domains\Seo\Services\CompetitorSpiderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompetitorController extends Controller
{
    /**
     * لیست رقبا.
     */
    public function index(Request $request): JsonResponse
    {
        $siteId = $request->user()->currentSiteId ?? $request->input('site_id', 1);

        $competitors = DB::table('competitors')
            ->where('site_id', $siteId)
            ->get()
            ->map(function ($c) {
                $pages = DB::table('competitor_pages')->where('competitor_id', $c->id)->count();
                $keywords = DB::table('competitor_keywords')->where('competitor_id', $c->id)->count();
                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'url' => $c->url,
                    'pages_crawled' => $pages,
                    'keywords_found' => $keywords,
                    'crawled_at' => $c->crawled_at,
                    'created_at' => $c->created_at,
                ];
            });

        return response()->json(['competitors' => $competitors]);
    }

    /**
     * افزودن و کرال رقیب جدید.
     */
    public function store(Request $request, CompetitorSpiderService $spider): JsonResponse
    {
        $request->validate([
            'url' => 'required|url',
            'name' => 'required|string|max:255',
        ]);

        $siteId = $request->user()->currentSiteId ?? $request->input('site_id', 1);
        $url = rtrim($request->url, '/');

        $result = $spider->crawlCompetitor($url, $request->name, $siteId);

        return response()->json([
            'ok' => true,
            'competitor_id' => $result['competitor_id'],
            'pages_crawled' => $result['pages_crawled'],
            'unique_topics' => $result['analysis']['unique_topics'],
            'gaps_found' => count($result['gaps']),
        ]);
    }

    /**
     * جزئیات یک رقیب (صفحات + کلمات کلیدی).
     */
    public function show(int $id): JsonResponse
    {
        $competitor = DB::table('competitors')->where('id', $id)->first();
        if (! $competitor) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $pages = DB::table('competitor_pages')
            ->where('competitor_id', $id)
            ->orderByDesc('word_count')
            ->limit(50)
            ->get();

        $keywords = DB::table('competitor_keywords')
            ->where('competitor_id', $id)
            ->orderByDesc('occurrences')
            ->limit(30)
            ->get();

        $typeDistribution = DB::table('competitor_pages')
            ->where('competitor_id', $id)
            ->select('content_type', DB::raw('count(*) as cnt'))
            ->groupBy('content_type')
            ->pluck('cnt', 'content_type')
            ->toArray();

        $avgWords = DB::table('competitor_pages')
            ->where('competitor_id', $id)
            ->avg('word_count');

        return response()->json([
            'competitor' => [
                'id' => $competitor->id,
                'name' => $competitor->name,
                'url' => $competitor->url,
            ],
            'pages' => $pages,
            'keywords' => $keywords,
            'type_distribution' => $typeDistribution,
            'avg_word_count' => round((float) $avgWords),
            'total_pages' => count($pages),
        ]);
    }

    /**
     * حذف رقیب.
     */
    public function destroy(int $id): JsonResponse
    {
        DB::table('competitor_keywords')->where('competitor_id', $id)->delete();
        DB::table('competitor_pages')->where('competitor_id', $id)->delete();
        DB::table('competitors')->where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * مقایسه رقبا با سایت خودمان.
     */
    public function compare(Request $request): JsonResponse
    {
        $siteId = $request->user()->currentSiteId ?? $request->input('site_id', 1);

        $ourProfiles = DB::table('url_profiles')->where('site_id', $siteId)->count();
        $ourKeywords = DB::table('url_profiles')
            ->where('site_id', $siteId)
            ->pluck('metadata')
            ->map(fn ($m) => json_decode($m, true)['title'] ?? '')
            ->filter()
            ->count();

        $competitors = DB::table('competitors')->where('site_id', $siteId)->get();
        $comparison = [];

        foreach ($competitors as $c) {
            $theirPages = DB::table('competitor_pages')->where('competitor_id', $c->id)->count();
            $theirAvgWords = DB::table('competitor_pages')->where('competitor_id', $c->id)->avg('word_count') ?? 0;
            $theirKeywords = DB::table('competitor_keywords')->where('competitor_id', $c->id)->count();

            // شکاف‌ها
            $gapOpps = DB::table('static_opportunities')
                ->where('site_id', $siteId)
                ->where('source', 'competitor_gap')
                ->whereRaw("metadata->>'competitor_id' = ?", [$c->id])
                ->count();

            $comparison[] = [
                'id' => $c->id,
                'name' => $c->name,
                'their_pages' => $theirPages,
                'our_pages' => $ourProfiles,
                'their_avg_words' => (int) round($theirAvgWords),
                'their_keywords' => $theirKeywords,
                'content_gaps' => $gapOpps,
            ];
        }

        return response()->json([
            'our_site' => ['pages' => $ourProfiles, 'keywords' => $ourKeywords],
            'competitors' => $comparison,
        ]);
    }
}
