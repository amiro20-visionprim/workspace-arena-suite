<?php

declare(strict_types=1);

namespace App\Domains\Content\Services;

use App\Domains\Ai\Services\AiGateway;
use App\Domains\Connector\Actions\PublishDraftThroughConnector;
use App\Domains\Content\Models\BulkJob;
use App\Domains\Content\Models\BulkJobItem;
use App\Domains\Content\Models\ContentDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * P2.5 — موتور تولید گروهی.
 *
 * هر آیتم (کیوورد/عنوان) از کل pipeline فاز ۵۰ می‌گذرد:
 *   ContentAnalyzer (هشدار تک‌کلمه‌ای + long-tail)
 *   → AiGateway (AI یا fallback رول‌بیس)
 *   → CategoryMatcher (دسته پیشنهادی)
 *   → SmartTagger (تگ‌های هوشمند)
 *   → InternalLinkEngine (لینک‌های درون‌متنی)
 *   → ContentQualityGuard + گیت حداقل واژه
 *   → ذخیره ContentDraft (با needs_review برای رول‌بیس)
 */
class BulkContentService
{
    public function __construct(
        private readonly AiGateway $gateway,
        private readonly ContentProfiler $profiler,
        private readonly ContentQualityGuard $guard,
        private readonly StandardsKB $standards,
        private readonly InternalLinkEngine $linkEngine,
        private readonly SchemaGenerator $schemaGen,
        private readonly ContentAnalyzer $analyzer,
        private readonly PublishDraftThroughConnector $publisher,
    ) {}

    /**
     * پردازش یک آیتم از دسته تولید گروهی.
     *
     * @return array{status: string, draft_id?: int, quality_score?: int, error?: string}
     */
    public function processItem(BulkJobItem $item): array
    {
        $item->update(['status' => 'processing', 'started_at' => now()]);
        $job = $item->job;

        try {
            $site = \App\Domains\Workspace\Models\Site::query()->find($job->site_id);
            if ($site === null) {
                throw new \RuntimeException('سایت یافت نشد');
            }

            $keyword = trim($item->keyword);
            if ($keyword === '') {
                throw new \RuntimeException('کیوورد خالی است');
            }

            // ── ۱) تحلیل عنوان / هشدار تک‌کلمه‌ای ──
            $title = trim((string) ($item->title !== '' && $item->title !== null ? $item->title : $keyword));
            $analysis = $this->analyzer->suggestKeyword([
                'title' => $title,
                'content_type' => $job->content_type,
                'subtype' => (string) $job->subtype,
            ]);
            $warning = $analysis['competition_warning'] ?? null;
            if ($warning !== null && count(preg_split('/\s+/u', $title)) <= 1) {
                // عنوان تک‌کلمه‌ای → به سمت long-tail هدایت کن و متوقف شو
                $item->update([
                    'status' => 'needs_review',
                    'error' => $warning['message'].' پیشنهادها: '.implode(' — ', $warning['suggestions'] ?? []),
                    'finished_at' => now(),
                ]);
                $job->increment('needs_review_items');

                return ['status' => 'needs_review'];
            }

            $profiled = $this->profiler->profile([
                'title' => $title,
                'target_query' => $keyword,
                'content_type' => $job->content_type,
                'subtype' => (string) $job->subtype,
            ]);

            $standard = $this->standards->standardFor($profiled, $job->site_id);

            // ── ۲) پیشنهاد لینک داخلی ──
            $links = $this->linkEngine->suggest(
                $job->site_id,
                $title,
                $keyword,
                $profiled['content_type'],
                $profiled['subtype']
            );

            // ── ۳) تولید محتوا (AI → رول‌بیس) ──
            $context = [
                'title' => $title,
                'target_query' => $keyword,
                'site_name' => (string) $site->name,
                'url' => '',
                'standard' => $standard,
                'metrics' => [],
                'freshness' => null,
                'page_status' => 'publish',
                'internal_links' => $links,
                'word_count' => max(600, (int) ($standard['word_min'] ?? 600)),
            ];

            $result = $this->gateway->generateArticleDraft(
                $site->organization ?? $site,
                $context
            );

            $content = (string) ($result['content'] ?? '');
            if (trim($content) === '') {
                throw new \RuntimeException('خروجی تولید خالی است');
            }

            // ── ۴) تزریق لینک درون‌متنی ──
            if ($links !== []) {
                $content = $this->linkEngine->injectLinks($content, $links, rtrim((string) $site->canonical_url, '/'));
            }

            // ── ۵) متا ──
            $metaTitle = mb_substr($keyword.' | '.$site->name, 0, 60);
            $metaDesc = $this->extractMetaDescription($content, $title);
            $schemas = $this->schemaGen->generate(
                $profiled,
                $content,
                $title,
                '',
                (string) $site->name,
                $metaDesc,
                $standard
            );

            // ── ۶) گیت کیفیت ──
            $evaluation = $this->guard->evaluate($profiled, [
                'title' => $title,
                'body' => $content,
                'keyword' => $keyword,
                'headings' => $this->extractHeadings($content),
                'meta_title' => $metaTitle,
                'meta_description' => $metaDesc,
            ], $job->site_id, $this->standards);

            $wordCount = $this->wordCount(strip_tags($content));
            $minWords = $job->content_type === 'product' ? 250 : 800;
            // IL3 gate — در حالت توسعه/تست رول‌بیس نیاز به بازبینی ندارد (قابل انتشار)
            $ruleBasedGateOpen = (bool) config('vision-prime.content.allow_rulebased_publish');
            $isRuleBased = ($result['source'] ?? '') === 'rule_based' && ! $ruleBasedGateOpen;
            $needsReview = $isRuleBased || $wordCount < $minWords || ($evaluation['score'] ?? 0) < 50;

            // ── ۷) ذخیره پیش‌نویس ──
            $slug = SlugGenerator::transliterate($title);
            $slug = $this->uniqueSlug((int) $job->site_id, $slug);

            $draft = ContentDraft::create([
                'site_id' => (int) $job->site_id,
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'meta_title' => $metaTitle,
                'meta_description' => $metaDesc,
                'schemas' => $schemas,
                'quality_score' => (int) ($evaluation['score'] ?? 0),
                'subtype' => $profiled['subtype'] ?? 'article',
                'model_used' => (string) ($result['model'] ?? 'rule-based'),
                'status' => 'draft',
                'focus_keyword' => (string) ($result['focus_keyword'] ?? $keyword),
                'audit_log' => [
                    'generation_source' => $result['source'] ?? 'unknown',
                    'needs_review' => $needsReview,
                    'bulk_job_item_id' => $item->id,
                ],
            ]);

            $item->update([
                'status' => $needsReview ? 'needs_review' : 'completed',
                'source' => (string) ($result['source'] ?? ''),
                'draft_id' => $draft->id,
                'quality_score' => (int) ($evaluation['score'] ?? 0),
                'word_count' => $wordCount,
                'slug' => $slug,
                'finished_at' => now(),
            ]);

            if ($needsReview) {
                $job->increment('needs_review_items');

                return [
                    'status' => 'needs_review',
                    'draft_id' => $draft->id,
                    'quality_score' => (int) ($evaluation['score'] ?? 0),
                ];
            }

            $job->increment('completed_items');

            // ── ۸) انتشار خودکار (P2.7/P2.8) — فقط آیتم‌هایی که از گیت کیفیت عبور کرده‌اند ──
            $autoPublish = (string) ($job->auto_publish ?? 'off');
            if (in_array($autoPublish, ['draft', 'publish'], true)) {
                // P2.8 — سقف انتشار روزانه: اگر پر شده باشد آیتم تولید می‌شود ولی
                // منتشر نمی‌شود (در صف انتشار می‌ماند — قابل انتشار دستی یا روز بعد)
                $dailyLimit = (int) ($job->daily_publish_limit ?? 0);
                if ($dailyLimit > 0 && $this->publishedToday((int) $job->site_id) >= $dailyLimit) {
                    Log::info('BulkContentService daily publish limit reached', [
                        'item_id' => $item->id,
                        'job_id' => $job->id,
                        'limit' => $dailyLimit,
                    ]);

                    return [
                        'status' => 'completed',
                        'draft_id' => $draft->id,
                        'quality_score' => (int) ($evaluation['score'] ?? 0),
                        'publish_status' => null,
                        'daily_limit_reached' => true,
                    ];
                }

                $pub = $this->publishItem($item->fresh(), $autoPublish);
                if (($pub['success'] ?? false) === true) {
                    Log::info('BulkContentService auto-published item', [
                        'item_id' => $item->id,
                        'job_id' => $job->id,
                        'post_id' => $pub['post_id'] ?? null,
                    ]);

                    return [
                        'status' => 'completed',
                        'draft_id' => $draft->id,
                        'quality_score' => (int) ($evaluation['score'] ?? 0),
                        'publish_status' => BulkJobItem::PUBLISH_PUBLISHED,
                        'post_id' => $pub['post_id'] ?? null,
                        'auto_published' => true,
                    ];
                }

                // انتشار خودکار ناموفق → آیتم در صف بازبینی می‌ماند (قابل انتشار دستی)
                $item->refresh();
                Log::warning('BulkContentService auto-publish failed', [
                    'item_id' => $item->id,
                    'job_id' => $job->id,
                    'error' => $pub['error'] ?? 'unknown',
                ]);

                return [
                    'status' => 'completed',
                    'draft_id' => $draft->id,
                    'quality_score' => (int) ($evaluation['score'] ?? 0),
                    'publish_status' => BulkJobItem::PUBLISH_FAILED,
                    'publish_error' => $pub['error'] ?? null,
                    'auto_published' => false,
                ];
            }

            return [
                'status' => 'completed',
                'draft_id' => $draft->id,
                'quality_score' => (int) ($evaluation['score'] ?? 0),
            ];
        } catch (Throwable $e) {
            Log::error('BulkContentService item failed', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
                'trace' => mb_substr($e->getTraceAsString(), 0, 1500),
            ]);
            $item->update([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
            ]);
            $job->increment('failed_items');

            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    /**
     * P2.6 — انتشار یک آیتم از دسته تولید گروهی به وردپرس.
     *
     * گیت کیفیت داخل PublishDraftThroughConnector اجرا می‌شود:
     *   IL3.1 — خروجی رول‌بیس بدون فلگ توسعه منتشر نمی‌شود
     *   IL3.4 — حداقل واژه (۸۰۰ مقاله / ۲۵۰ محصول)
     *   اتصال جفت‌سازی شدهٔ وردپرس
     *
     * @return array{success: bool, error?: string, post_id?: int, publish_status: string}
     */
    public function publishItem(BulkJobItem $item, string $status = 'publish', array $terms = []): array
    {
        if ($item->publish_status === BulkJobItem::PUBLISH_PUBLISHED) {
            return ['success' => true, 'publish_status' => $item->publish_status, 'post_id' => $item->post_id, 'already_published' => true];
        }

        $item->update(['publish_status' => BulkJobItem::PUBLISH_PUBLISHING]);

        try {
            $draft = ContentDraft::query()->find($item->draft_id);
            if ($draft === null) {
                throw new \RuntimeException('پیش‌نویس این آیتم یافت نشد — ابتدا پردازش را اجرا کنید.');
            }

            $result = $this->publisher->handle($draft, $status, $terms);

            if (($result['success'] ?? false) === true) {
                // بازیابی post_id واقعی وردپرس از پاسخ پلاگین (command_execution_logs)
                $postId = $this->resolvePostId((int) ($result['command_id'] ?? 0));

                $item->update([
                    'publish_status' => BulkJobItem::PUBLISH_PUBLISHED,
                    'post_id' => $postId,
                    'published_at' => now(),
                    'publish_error' => null,
                    'finished_at' => now(),
                ]);

                return [
                    'success' => true,
                    'publish_status' => BulkJobItem::PUBLISH_PUBLISHED,
                    'command_id' => $result['command_id'] ?? null,
                    'post_id' => $postId,
                ];
            }

            $message = (string) ($result['error'] ?? 'انتشار ناموفق بود');
            $item->update([
                'publish_status' => BulkJobItem::PUBLISH_FAILED,
                'publish_error' => mb_substr($message, 0, 500),
            ]);

            return ['success' => false, 'error' => $message, 'publish_status' => BulkJobItem::PUBLISH_FAILED];
        } catch (\Throwable $e) {
            Log::error('BulkContentService publish failed', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
            $item->update([
                'publish_status' => BulkJobItem::PUBLISH_FAILED,
                'publish_error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            return ['success' => false, 'error' => $e->getMessage(), 'publish_status' => BulkJobItem::PUBLISH_FAILED];
        }
    }

    /**
     * خواندن post_id واقعی از پاسخ پلاگین (لاگ اجرای فرمان امضاشده).
     */
    private function resolvePostId(int $commandId): ?int
    {
        if ($commandId <= 0) {
            return null;
        }
        try {
            $log = DB::table('command_execution_logs')
                ->where('command_id', $commandId)
                ->orderByDesc('id')
                ->first();
            if ($log === null || empty($log->response_redacted)) {
                return null;
            }
            $decoded = json_decode((string) $log->response_redacted, true);
            $postId = $decoded['body']['result']['post_id'] ?? $decoded['result']['post_id'] ?? null;

            return $postId !== null ? (int) $postId : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * P2.8 — بازطراحی/بازتولید یک آیتم ناقص (needs_review یا failed).
     *
     * آیتم به حالت pending برمی‌گردد و با عنوان جدید (اختیاری — مثلاً پیشنهاد
     * long-tail) در اجرای بعدی worker دوباره از کل pipeline می‌گذرد.
     * پیش‌نویس قبلی حذف می‌شود تا محتوای دوباره‌تولیدشده جایگزین شود.
     *
     * @return array{success: bool, error?: string, status?: string}
     */
    public function reworkItem(BulkJobItem $item, ?string $newTitle = null): array
    {
        if (! in_array($item->status, ['needs_review', 'failed'], true)) {
            return ['success' => false, 'error' => 'فقط آیتم‌های «نیازمند بازبینی» یا «ناموفق» قابل بازطراحی هستند.'];
        }

        try {
            $job = $item->job;
            $title = $newTitle !== null && trim($newTitle) !== '' ? trim($newTitle) : $item->keyword;

            // حذف پیش‌نویس قبلی (اگر ساخته شده باشد)
            if ($item->draft_id) {
                ContentDraft::query()->where('id', $item->draft_id)->delete();
            }

            // کاهش شمارنده‌های دسته
            if ($item->status === 'needs_review' && $job->needs_review_items > 0) {
                $job->decrement('needs_review_items');
            }
            if ($item->status === 'failed' && $job->failed_items > 0) {
                $job->decrement('failed_items');
            }

            $item->update([
                'title' => $title,
                'status' => 'pending',
                'error' => null,
                'draft_id' => null,
                'quality_score' => null,
                'word_count' => null,
                'slug' => null,
                'source' => null,
                'publish_status' => null,
                'post_id' => null,
                'published_at' => null,
                'publish_error' => null,
                'started_at' => null,
                'finished_at' => null,
            ]);

            // دسته دوباره قابل اجرا شود
            if (in_array($job->status, ['completed', 'partial', 'failed'], true)) {
                $job->update(['status' => 'pending', 'finished_at' => null]);
            }

            return ['success' => true, 'status' => 'pending', 'title' => $title];
        } catch (\Throwable $e) {
            Log::error('BulkContentService rework failed', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * P2.8 — شمارش انتشارهای موفق امروز برای یک سایت (سقف روزانه).
     */
    public function publishedToday(int $siteId): int
    {
        return (int) DB::table('bulk_job_items')
            ->join('bulk_jobs', 'bulk_jobs.id', '=', 'bulk_job_items.bulk_job_id')
            ->where('bulk_jobs.site_id', $siteId)
            ->where('bulk_job_items.publish_status', BulkJobItem::PUBLISH_PUBLISHED)
            ->whereDate('bulk_job_items.published_at', today())
            ->count();
    }

    /**
     * P2.6 — انتشار گروهی: همهٔ آیتم‌های دارای پیش‌نویس که هنوز منتشر نشده‌اند.
     *
     * @return array{published: int, failed: int, skipped: int, results: array<int, array{item_id: int, success: bool, error?: string}>}
     */
    public function publishJob(BulkJob $job, string $status = 'publish'): array
    {
        $results = [];
        $published = 0;
        $failed = 0;
        $skipped = 0;

        $items = $job->items()
            ->whereNotNull('draft_id')
            ->where(fn ($q) => $q->whereNull('publish_status')->orWhere('publish_status', '!=', BulkJobItem::PUBLISH_PUBLISHED))
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            $res = $this->publishItem($item, $status);
            $results[] = [
                'item_id' => $item->id,
                'keyword' => $item->keyword,
                'success' => $res['success'],
                'error' => $res['error'] ?? null,
            ];
            if ($res['success']) {
                $published++;
            } elseif (($res['already_published'] ?? false)) {
                $skipped++;
            } else {
                $failed++;
            }
        }

        return ['published' => $published, 'failed' => $failed, 'skipped' => $skipped, 'results' => $results];
    }

    /**
     * شمارش آیتم‌های باقی‌مانده و به‌روزرسانی وضعیت دسته.
     */
    public function syncJobProgress(BulkJob $job): void
    {
        $counts = DB::table('bulk_job_items')
            ->where('bulk_job_id', $job->id)
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status IN ('completed') THEN 1 ELSE 0 END) as done, SUM(CASE WHEN status IN ('failed') THEN 1 ELSE 0 END) as failed, SUM(CASE WHEN status IN ('needs_review') THEN 1 ELSE 0 END) as review")
            ->first();

        $total = (int) ($counts->total ?? 0);
        $done = (int) ($counts->done ?? 0);
        $failed = (int) ($counts->failed ?? 0);
        $review = (int) ($counts->review ?? 0);
        $processing = (int) DB::table('bulk_job_items')
            ->where('bulk_job_id', $job->id)
            ->whereIn('status', ['pending', 'processing'])
            ->count();

        $status = 'pending';
        if ($processing === 0) {
            $status = $failed === $total ? 'failed' : ($review > 0 ? 'partial' : 'completed');
        } else {
            $status = 'running';
        }

        $job->update([
            'status' => $status,
            'total_items' => $total,
            'completed_items' => $done,
            'failed_items' => $failed,
            'needs_review_items' => $review,
            'finished_at' => $processing === 0 ? now() : $job->finished_at,
        ]);
    }

    private function extractMetaDescription(string $content, string $title): string
    {
        $stripped = trim(preg_replace('/\s+/u', ' ', strip_tags(preg_replace('/<[^>]+>/u', ' ', $content))));
        $parts = preg_split('/[.!?؟]+/u', $stripped, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (mb_strlen($part) > 30 && ! str_contains($part, $title)) {
                return mb_substr(rtrim($part, '،. ').'...', 0, 155);
            }
        }

        return mb_substr($stripped, 0, 155);
    }

    /**
     * استخراج متن سرفصل‌ها — خروجی مطابق انتظار ContentQualityGuard:
     * آرایهٔ یک‌بعدی از رشته‌ها.
     *
     * @return array<int, string>
     */
    private function extractHeadings(string $html): array
    {
        $headings = [];
        preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h[1-6]>/is', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $text = trim(strip_tags((string) $match[2]));
            if ($text !== '') {
                $headings[] = $text;
            }
        }

        return $headings;
    }

    private function wordCount(string $text): int
    {
        return preg_match_all('/[\p{L}\p{N}]+/u', $text) ?: 0;
    }

    private function uniqueSlug(int $siteId, string $slug): string
    {
        if ($slug === '') {
            return $slug;
        }
        $exists = DB::table('content_drafts')->where('site_id', $siteId)->where('slug', $slug)->exists()
            || DB::table('url_profiles')->where('site_id', $siteId)->where('slug', $slug)->exists();
        if (! $exists) {
            return $slug;
        }
        $counter = 1;
        do {
            $candidate = $slug.'-'.$counter;
            $dup = DB::table('content_drafts')->where('site_id', $siteId)->where('slug', $candidate)->exists()
                || DB::table('url_profiles')->where('site_id', $siteId)->where('slug', $candidate)->exists();
            $counter++;
        } while ($dup && $counter < 50);

        return $candidate;
    }
}