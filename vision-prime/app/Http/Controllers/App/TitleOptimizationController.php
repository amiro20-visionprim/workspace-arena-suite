<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Domains\Content\Services\TitleOptimizer;
use App\Domains\Content\Services\ContentRefreshService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TitleOptimizationController extends Controller
{
    /**
     * لیست محتواهای نیاز به بروزرسانی.
     */
    public function index(Request $request, ContentRefreshService $refreshService): JsonResponse
    {
        $siteId = $request->user()->currentSiteId ?? $request->input('site_id', 1);
        $candidates = $refreshService->findRefreshCandidates($siteId);

        return response()->json($candidates);
    }

    /**
     * تحلیل و بهینه‌سازی عنوان یک محتوای خاص.
     */
    public function optimize(Request $request, int $draftId, TitleOptimizer $optimizer): JsonResponse
    {
        $result = $optimizer->optimize($draftId);
        if (! $result) {
            return response()->json(['error' => 'Draft not found'], 404);
        }
        return response()->json($result);
    }

    /**
     * پیشنهاد بروزرسانی برای یک محتوا.
     */
    public function suggestRefresh(Request $request, int $draftId, ContentRefreshService $refreshService): JsonResponse
    {
        $result = $refreshService->suggestRefresh($draftId);
        if (! $result) {
            return response()->json(['error' => 'Draft not found'], 404);
        }
        return response()->json($result);
    }

    /**
     * اعمال عنوان پیشنهادی.
     */
    public function applyTitle(Request $request, int $draftId): JsonResponse
    {
        $request->validate(['title' => 'required|string|max:255']);

        DB::table('content_drafts')
            ->where('id', $draftId)
            ->update(['title' => $request->title, 'updated_at' => now()]);

        return response()->json(['ok' => true, 'new_title' => $request->title]);
    }

    /**
     * خلاصه وضعیت بروزرسانی محتوا.
     */
    public function summary(Request $request, ContentRefreshService $refreshService): JsonResponse
    {
        $siteId = $request->user()->currentSiteId ?? $request->input('site_id', 1);
        $candidates = $refreshService->findRefreshCandidates($siteId);

        $totalDrafts = DB::table('content_drafts')->where('site_id', $siteId)->count();
        $avgQuality = DB::table('content_drafts')->where('site_id', $siteId)->avg('quality_score') ?? 0;

        return response()->json([
            'total_drafts' => $totalDrafts,
            'avg_quality' => round((float) $avgQuality, 1),
            'needs_refresh' => $candidates['total'],
            'stale_count' => count($candidates['stale']),
            'low_quality_count' => count($candidates['low_quality']),
            'weak_title_count' => count($candidates['weak_titles']),
        ]);
    }
}
