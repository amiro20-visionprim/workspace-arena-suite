<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domains\Content\Models\BulkJob;
use App\Domains\Content\Models\BulkJobItem;
use App\Domains\Content\Services\BulkContentService;
use App\Domains\Organization\Contracts\CurrentOrganization;
use App\Domains\Workspace\Models\Site;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Inertia\Inertia;
use Inertia\Response;

/**
 * P2.5 — تولید گروهی محتوا.
 *
 * Routes:
 *   POST   /api/bulk-content/jobs        ساخت دسته + آیتم‌ها
 *   GET    /api/bulk-content/jobs        لیست دسته‌ها
 *   GET    /api/bulk-content/jobs/{id}   جزئیات + آیتم‌ها
 *   POST   /api/bulk-content/jobs/{id}/run   شروع پردازش (worker در پس‌زمینه)
 */
class BulkContentController extends Controller
{
    /**
     * سقف تولید روزانه هر سایت (P2.5.5 — Rate limit بر اساس سهمیه AI).
     */
    private const DAILY_LIMIT = 30;

    /**
     * صفحهٔ داشبورد تولید گروهی (P2.5.4).
     */
    public function page(CurrentOrganization $org): Response
    {
        $sites = Site::query()
            ->where('organization_id', $org->id())
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('App/BulkContent', [
            'sites' => $sites,
        ]);
    }

    public function store(Request $request, CurrentOrganization $org): JsonResponse
    {
        $data = $request->validate([
            'site_id' => 'required|integer',
            'name' => 'nullable|string|max:120',
            'content_type' => 'nullable|string|in:article,product',
            'subtype' => 'nullable|string|max:50',
            'auto_publish' => 'nullable|string|in:off,draft,publish',
            'scheduled_at' => 'nullable|date|after_or_equal:now',
            'daily_publish_limit' => 'nullable|integer|min:0|max:100',
            'keywords' => 'required|array|min:1|max:50',
            'keywords.*' => 'required|string|max:300',
        ]);

        $site = Site::query()
            ->where('organization_id', $org->id())
            ->findOrFail($data['site_id']);

        // Rate limit روزانه (P2.5.5)
        $todayCount = (int) BulkJob::query()
            ->where('site_id', $site->id)
            ->whereDate('created_at', today())
            ->sum('total_items');
        $newCount = count($data['keywords']);
        if ($todayCount + $newCount > self::DAILY_LIMIT) {
            return response()->json([
                'error' => "سقف تولید روزانهٔ این سایت {$newCount} آیتم جدید را نمی‌پذیرد (مصرف امروز: {$todayCount} از {$this->DAILY_LIMIT}).",
            ], 422);
        }

        $job = BulkJob::create([
            'organization_id' => $org->id(),
            'site_id' => $site->id,
            'name' => $data['name'] ?? 'تولید گروهی '.now()->format('Y/m/d H:i'),
            'content_type' => $data['content_type'] ?? 'article',
            'subtype' => $data['subtype'] ?? '',
            'auto_publish' => $data['auto_publish'] ?? 'off',
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'daily_publish_limit' => (int) ($data['daily_publish_limit'] ?? 0),
            'status' => 'pending',
            'total_items' => count($data['keywords']),
            'created_by' => auth()->id(),
        ]);

        foreach ($data['keywords'] as $keyword) {
            $job->items()->create([
                'keyword' => trim($keyword),
                'title' => trim($keyword),
                'status' => 'pending',
            ]);
        }

        return response()->json([
            'id' => $job->id,
            'status' => 'pending',
            'total_items' => count($data['keywords']),
            'auto_publish' => $job->auto_publish,
            'scheduled_at' => $job->scheduled_at?->toISOString(),
            'daily_publish_limit' => $job->daily_publish_limit,
        ], 201);
    }

    public function index(Request $request, CurrentOrganization $org): JsonResponse
    {
        $jobs = BulkJob::query()
            ->where('organization_id', $org->id())
            ->withCount('items')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (BulkJob $j) => [
                'id' => $j->id,
                'name' => $j->name,
                'site_id' => $j->site_id,
                'status' => $j->status,
                'content_type' => $j->content_type,
                'auto_publish' => $j->auto_publish,
                'scheduled_at' => $j->scheduled_at?->toISOString(),
                'daily_publish_limit' => $j->daily_publish_limit,
                'total_items' => $j->total_items,
                'completed_items' => $j->completed_items,
                'failed_items' => $j->failed_items,
                'needs_review_items' => $j->needs_review_items,
                'progress' => $j->progressPercent(),
                'created_at' => $j->created_at?->toISOString(),
                'finished_at' => $j->finished_at?->toISOString(),
            ]);

        return response()->json(['jobs' => $jobs]);
    }

    public function show(Request $request, CurrentOrganization $org, int $id): JsonResponse
    {
        $job = BulkJob::query()
            ->where('organization_id', $org->id())
            ->with(['items' => fn ($q) => $q->orderBy('id')])
            ->findOrFail($id);

        return response()->json([
            'job' => [
                'id' => $job->id,
                'name' => $job->name,
                'site_id' => $job->site_id,
                'status' => $job->status,
                'content_type' => $job->content_type,
                'subtype' => $job->subtype,
                'auto_publish' => $job->auto_publish,
                'scheduled_at' => $job->scheduled_at?->toISOString(),
                'daily_publish_limit' => $job->daily_publish_limit,
                'total_items' => $job->total_items,
                'completed_items' => $job->completed_items,
                'failed_items' => $job->failed_items,
                'needs_review_items' => $job->needs_review_items,
                'progress' => $job->progressPercent(),
                'started_at' => $job->started_at?->toISOString(),
                'finished_at' => $job->finished_at?->toISOString(),
                'created_at' => $job->created_at?->toISOString(),
            ],
            'items' => $job->items->map(fn ($item) => [
                'id' => $item->id,
                'keyword' => $item->keyword,
                'title' => $item->title,
                'status' => $item->status,
                'source' => $item->source,
                'draft_id' => $item->draft_id,
                'quality_score' => $item->quality_score,
                'word_count' => $item->word_count,
                'slug' => $item->slug,
                'error' => $item->error,
                // P2.6
                'publish_status' => $item->publish_status,
                'post_id' => $item->post_id,
                'published_at' => $item->published_at?->toISOString(),
                'publish_error' => $item->publish_error,
            ]),
        ]);
    }

    /**
     * شروع پردازش دسته — worker را در پس‌زمینه spawn می‌کند.
     * در محیط CLI (کرون) هم می‌تواند مستقیم اجرا شود.
     */
    public function run(Request $request, CurrentOrganization $org, int $id): JsonResponse
    {
        $job = BulkJob::query()
            ->where('organization_id', $org->id())
            ->findOrFail($id);

        if (! in_array($job->status, ['pending', 'running', 'partial', 'failed'], true)) {
            return response()->json(['error' => 'این دسته قابل پردازش مجدد نیست.'], 422);
        }

        // P2.8 — گیت زمان‌بندی: قبل از زمان مقرر پردازش شروع نمی‌شود
        if ($job->scheduled_at !== null && $job->scheduled_at->isFuture()) {
            return response()->json([
                'error' => "این دسته برای «{$job->scheduled_at->format('Y/m/d H:i')}» زمان‌بندی شده است و هنوز زمان آن نرسیده.",
                'scheduled_at' => $job->scheduled_at->toISOString(),
            ], 422);
        }

        $artisan = base_path('artisan');
        $php = PHP_BINARY;

        // اجرای worker در پس‌زمینه (بدون نیاز به Supervisor)
        try {
            $process = Process::timeout(0)
                ->path(base_path())
                ->start("\"{$php}\" \"{$artisan}\" content:bulk-run --job={$job->id} > /dev/null 2>&1 &");

            return response()->json(['ok' => true, 'started' => true]);
        } catch (\Throwable $e) {
            // Fallback: اجرای مستقیم هم‌زمان (برای محیط‌هایی که shell ندارند)
            app(BulkContentService::class);
            \Artisan::call('content:bulk-run', ['--job' => $job->id]);

            return response()->json(['ok' => true, 'started' => false]);
        }
    }

    /**
     * P2.6 — انتشار یک آیتم به وردپرس (با گیت کیفیت).
     * POST /api/bulk-content/items/{id}/publish
     */
    public function publishItem(Request $request, CurrentOrganization $org, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'nullable|string|in:publish,draft,pending',
        ]);

        $item = BulkJobItem::query()
            ->whereHas('job', fn ($q) => $q->where('organization_id', $org->id()))
            ->findOrFail($id);

        $result = app(BulkContentService::class)->publishItem($item, $data['status'] ?? 'publish');

        return response()->json([
            'success' => $result['success'],
            'publish_status' => $result['publish_status'] ?? null,
            'error' => $result['error'] ?? null,
            'command_id' => $result['command_id'] ?? null,
            'already_published' => $result['already_published'] ?? false,
        ], $result['success'] ? 200 : 422);
    }

    /**
     * P2.6 — انتشار گروهی همهٔ آیتم‌های آمادهٔ یک دسته.
     * POST /api/bulk-content/jobs/{id}/publish
     */
    public function publishJob(Request $request, CurrentOrganization $org, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'nullable|string|in:publish,draft,pending',
        ]);

        $job = BulkJob::query()
            ->where('organization_id', $org->id())
            ->findOrFail($id);

        $result = app(BulkContentService::class)->publishJob($job, $data['status'] ?? 'publish');

        return response()->json([
            'published' => $result['published'],
            'failed' => $result['failed'],
            'skipped' => $result['skipped'],
            'results' => $result['results'],
        ]);
    }

    /**
     * P2.8 — بازطراحی/بازتولید یک آیتم ناقص (needs_review/failed).
     * POST /api/bulk-content/items/{id}/rework  body: {title?}
     */
    public function rework(Request $request, CurrentOrganization $org, int $id): JsonResponse
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:300',
        ]);

        $item = BulkJobItem::query()
            ->whereHas('job', fn ($q) => $q->where('organization_id', $org->id()))
            ->findOrFail($id);

        $result = app(BulkContentService::class)->reworkItem($item, $data['title'] ?? null);
        if (! ($result['success'] ?? false)) {
            return response()->json(['error' => $result['error'] ?? 'بازطراحی ناموفق بود.'], 422);
        }

        return response()->json([
            'success' => true,
            'status' => 'pending',
            'title' => $result['title'],
            'job_id' => $item->bulk_job_id,
        ]);
    }

    /**
     * P2.8 — گزارش خلاصهٔ کیفیت تولید گروهی.
     * GET /api/bulk-content/quality-report
     */
    public function qualityReport(Request $request, CurrentOrganization $org): JsonResponse
    {
        $items = DB::table('bulk_job_items')
            ->join('bulk_jobs', 'bulk_jobs.id', '=', 'bulk_job_items.bulk_job_id')
            ->where('bulk_jobs.organization_id', $org->id())
            ->get([
                'bulk_job_items.status',
                'bulk_job_items.quality_score',
                'bulk_job_items.publish_status',
                'bulk_job_items.error',
                'bulk_job_items.publish_error',
            ]);

        $total = $items->count();
        $withScore = $items->filter(fn ($i) => $i->quality_score !== null);
        $published = $items->where('publish_status', 'published')->count();
        $rejected = $items->where('publish_status', 'failed')->count();
        $review = $items->where('status', 'needs_review')->count();
        $failedGen = $items->where('status', 'failed')->count();
        $avgScore = $withScore->count() > 0
            ? round($withScore->avg('quality_score'), 1)
            : null;

        // دلایل رایج رد شدن (گروه‌بندی پیام‌ها)
        $reasons = [];
        foreach ($items as $i) {
            $msg = (string) ($i->publish_error ?: $i->error);
            if ($msg === '') {
                continue;
            }
            $key = match (true) {
                str_contains($msg, 'تک‌کلمه‌ای') => 'عنوان تک‌کلمه‌ای رقابتی (نیاز به long-tail)',
                str_contains($msg, 'واژه') => 'کم‌بودن تعداد واژه (زیر حداقل گیت)',
                str_contains($msg, 'جفت نشده') => 'وردپرس جفت نشده (نیاز به اتصال)',
                str_contains($msg, 'رول‌بیس') || str_contains($msg, 'نمونه') => 'خروجی نمونه (رول‌بیس) بدون تأیید',
                str_contains($msg, 'پیش‌نویس این آیتم') => 'پیش‌نویس موجود نیست (پردازش نشده)',
                default => mb_substr($msg, 0, 60),
            };
            $reasons[$key] = ($reasons[$key] ?? 0) + 1;
        }
        arsort($reasons);
        $reasons = array_slice($reasons, 0, 8, true);

        // پیشنهادهای بهبود بر اساس داده
        $suggestions = [];
        if ($total > 0 && $published / $total < 0.5) {
            $suggestions[] = 'نرخ انتشار زیر ۵۰٪ است — بیشتر آیتم‌ها توسط گیت کیفیت متوقف می‌شوند. پیشنهاد: کیووردهای long-tail و دقیق‌تر انتخاب کنید.';
        }
        if (($reasons['عنوان تک‌کلمه‌ای رقابتی (نیاز به long-tail)'] ?? 0) > 0) {
            $suggestions[] = 'تعداد قابل توجهی عنوان تک‌کلمه‌ای دارید — از پیشنهادهای long-tail خودکار استفاده کنید یا عنوان را دقیق‌تر کنید.';
        }
        if ($avgScore !== null && $avgScore < 65) {
            $suggestions[] = "میانگین امتیاز کیفیت ({$avgScore}) پایین است — زیرنوع محتوا را بررسی کنید (راهنمای خرید/آموزشی معمولاً امتیاز بالاتری دارند).";
        }
        if (($reasons['وردپرس جفت نشده (نیاز به اتصال)'] ?? 0) > 0) {
            $suggestions[] = 'چند آیتم به دلیل عدم اتصال وردپرس رد شده‌اند — از صفحهٔ «اتصال» سایت، پلاگین را جفت‌سازی کنید.';
        }
        if ($suggestions === [] && $total > 0) {
            $suggestions[] = 'همه‌چیز سالم است — همین روند را ادامه دهید.';
        }

        return response()->json([
            'total' => $total,
            'published' => $published,
            'rejected' => $rejected,
            'review_queue' => $review,
            'failed_generation' => $failedGen,
            'avg_quality_score' => $avgScore,
            'publish_rate' => $total > 0 ? round($published / $total * 100) : 0,
            'rejection_reasons' => $reasons,
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * P2.7 — آمار انتشار گروهی برای کارت داشبورد.
     * GET /api/bulk-content/stats
     */
    public function stats(Request $request, CurrentOrganization $org): JsonResponse
    {
        $published = DB::table('bulk_job_items')
            ->join('bulk_jobs', 'bulk_jobs.id', '=', 'bulk_job_items.bulk_job_id')
            ->where('bulk_jobs.organization_id', $org->id())
            ->where('bulk_job_items.publish_status', 'published')
            ->count();

        $publishFailed = DB::table('bulk_job_items')
            ->join('bulk_jobs', 'bulk_jobs.id', '=', 'bulk_job_items.bulk_job_id')
            ->where('bulk_jobs.organization_id', $org->id())
            ->where('bulk_job_items.publish_status', 'failed')
            ->count();

        $reviewQueue = DB::table('bulk_job_items')
            ->join('bulk_jobs', 'bulk_jobs.id', '=', 'bulk_job_items.bulk_job_id')
            ->where('bulk_jobs.organization_id', $org->id())
            ->where('bulk_job_items.status', 'needs_review')
            ->count();

        $recentPublished = DB::table('bulk_job_items')
            ->join('bulk_jobs', 'bulk_jobs.id', '=', 'bulk_job_items.bulk_job_id')
            ->join('sites', 'sites.id', '=', 'bulk_jobs.site_id')
            ->where('bulk_jobs.organization_id', $org->id())
            ->where('bulk_job_items.publish_status', 'published')
            ->orderByDesc('bulk_job_items.published_at')
            ->limit(15)
            ->get([
                'bulk_job_items.id',
                'bulk_job_items.keyword',
                'bulk_job_items.post_id',
                'bulk_job_items.published_at',
                'bulk_job_items.publish_status',
                'sites.name as site_name',
                'sites.canonical_url as site_url',
            ])
            ->map(fn ($row) => [
                'id' => $row->id,
                'keyword' => $row->keyword,
                'post_id' => $row->post_id,
                'published_at' => $row->published_at ? date('c', strtotime($row->published_at)) : null,
                'site_name' => $row->site_name,
                'post_url' => $row->post_id && $row->site_url ? rtrim((string) $row->site_url, '/').'/?p='.$row->post_id : null,
            ]);

        return response()->json([
            'published' => $published,
            'publish_failed' => $publishFailed,
            'review_queue' => $reviewQueue,
            'recent_published' => $recentPublished,
        ]);
    }

    /**
     * وضعیت سریع برای پولینگ داشبورد.
     */
    public function status(Request $request, CurrentOrganization $org, int $id): JsonResponse
    {
        $job = BulkJob::query()
            ->where('organization_id', $org->id())
            ->findOrFail($id);

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'progress' => $job->progressPercent(),
            'completed_items' => $job->completed_items,
            'failed_items' => $job->failed_items,
            'needs_review_items' => $job->needs_review_items,
            'finished_at' => $job->finished_at?->toISOString(),
        ]);
    }
}