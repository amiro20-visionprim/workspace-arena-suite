<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Domains\Content\Services\TitleAbTestService;
use App\Domains\Content\Services\TitleOptimizer;
use App\Domains\Content\Services\AbTestPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TitleAbTestController extends Controller
{
    /**
     * لیست تست‌ها + خلاصه.
     */
    public function index(Request $request, TitleAbTestService $service): JsonResponse
    {
        $siteId = $request->user()->currentSiteId ?? $request->input('site_id', 1);
        $status = $request->input('status');

        return response()->json([
            'summary' => $service->getSummary($siteId),
            'tests' => $service->listTests($siteId, $status),
        ]);
    }

    /**
     * ایجاد تست جدید از عنوان موجود.
     */
    public function store(Request $request, TitleAbTestService $service, TitleOptimizer $optimizer): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'draft_id' => 'nullable|integer',
            'variant_titles' => 'required|array|min:1|max:5',
            'variant_titles.*' => 'required|string|max:255',
            'min_impressions' => 'nullable|integer|min:10|max:10000',
            'duration_days' => 'nullable|integer|min:1|max:30',
            'auto_update' => 'nullable|boolean',
            'wordpress_post_id' => 'nullable|integer',
        ]);

        $siteId = $request->user()->currentSiteId ?? $request->input('site_id', 1);

        $test = $service->createTest(
            siteId: $siteId,
            originalTitle: $request->title,
            variantTitles: $request->variant_titles,
            draftId: $request->draft_id,
            minImpressions: $request->input('min_impressions', 100),
            durationDays: $request->input('duration_days', 7),
            autoUpdate: $request->boolean('auto_update', false),
            wordpressPostId: $request->input('wordpress_post_id'),
        );

        return response()->json($test, 201);
    }

    /**
     * ایجاد تست خودکار از پیشنهادات TitleOptimizer.
     */
    public function autoCreate(Request $request, TitleAbTestService $service, TitleOptimizer $optimizer): JsonResponse
    {
        $request->validate([
            'draft_id' => 'required|integer',
        ]);

        $optimization = $optimizer->optimize($request->draft_id);
        if (! $optimization || empty($optimization['alternatives'])) {
            return response()->json(['error' => 'عنوان جایگزینی پیدا نشد'], 404);
        }

        $variants = array_slice(array_map(fn ($a) => $a['title'], $optimization['alternatives']), 0, 4);

        $siteId = $request->user()->currentSiteId ?? $request->input('site_id', 1);

        $test = $service->createTest(
            siteId: $siteId,
            originalTitle: $optimization['current_title'],
            variantTitles: $variants,
            draftId: $request->draft_id,
        );

        // شروع خودکار
        $test = $service->startTest($test['id']);

        return response()->json($test, 201);
    }

    /**
     * دریافت جزئیات یک تست.
     */
    public function show(int $id, TitleAbTestService $service): JsonResponse
    {
        $test = $service->getTest($id);
        if (! $test) {
            return response()->json(['error' => 'تست پیدا نشد'], 404);
        }
        return response()->json($test);
    }

    /**
     * شروع تست.
     */
    public function start(int $id, TitleAbTestService $service): JsonResponse
    {
        $test = $service->startTest($id);
        return response()->json($test);
    }

    /**
     * توقف تست.
     */
    public function pause(int $id, TitleAbTestService $service): JsonResponse
    {
        $test = $service->pauseTest($id);
        return response()->json($test);
    }

    /**
     * ردیابی رویداد (impression یا click).
     * این endpoint توسط وردپرس فراخوانی میشه.
     */
    public function track(Request $request, TitleAbTestService $service): JsonResponse
    {
        $request->validate([
            'variant_id' => 'required|integer',
            'event_type' => 'required|in:impression,click',
        ]);

        $visitorHash = md5($request->ip() . ($request->userAgent() ?? ''));

        $service->trackEvent(
            variantId: $request->variant_id,
            eventType: $request->event_type,
            url: $request->input('url'),
            visitorHash: $visitorHash,
        );

        return response()->json(['ok' => true]);
    }

    /**
     * دریافت نتیجه نهایی تست (برنده + بهبود CTR).
     */
    public function result(int $id, TitleAbTestService $service): JsonResponse
    {
        $test = $service->getTest($id);
        if (! $test) {
            return response()->json(['error' => 'تست پیدا نشد'], 404);
        }

        if ($test['status'] !== 'completed') {
            return response()->json(['error' => 'تست هنوز تموم نشده', 'status' => $test['status']]);
        }

        $significance = $test['significance'];
        $winner = collect($significance['details'])->firstWhere('is_winner', true);
        $original = collect($significance['details'])->firstWhere('is_original', true);

        $improvement = 0;
        if ($original && $winner && $original['impressions'] > 0) {
            $originalCtr = ($original['clicks'] / $original['impressions']) * 100;
            $winnerCtr = ($winner['clicks'] / $winner['impressions']) * 100;
            if ($originalCtr > 0) {
                $improvement = round((($winnerCtr - $originalCtr) / $originalCtr) * 100, 1);
            }
        }

        return response()->json([
            'test_id' => $test['id'],
            'winner' => $winner,
            'original' => $original,
            'confidence' => $significance['confidence'],
            'improvement_percent' => $improvement,
            'all_variants' => $significance['details'],
        ]);
    }

    /**
     * اعمال دستی برنده در وردپرس.
     */
    public function applyWinner(Request $request, int $id, TitleAbTestService $service, AbTestPublisher $publisher): JsonResponse
    {
        $request->validate([
            'variant_id' => 'required|integer',
            'wordpress_post_id' => 'required|integer',
        ]);

        $result = $publisher->applyWinner(
            testId: $id,
            variantId: $request->variant_id,
            postId: $request->wordpress_post_id,
        );

        if (! $result['ok']) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json(['ok' => true, 'command_id' => $result['command_id']]);
    }

    /**
     * دریافت لاگ انتشار یک تست.
     */
    public function publishLog(int $id, AbTestPublisher $publisher): JsonResponse
    {
        return response()->json(['logs' => $publisher->getPublishLog($id)]);
    }
}
