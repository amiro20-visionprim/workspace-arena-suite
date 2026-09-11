<?php

declare(strict_types=1);

namespace App\Domains\Content\Services;

use App\Domains\Automation\Services\AdaptiveLearning;
use App\Domains\Automation\Services\DecisionEngine;
use App\Domains\Content\Models\BulkJob;
use App\Domains\Content\Models\BulkJobItem;
use App\Domains\Workspace\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * قدم ۴ اکوسیستم — OpportunityPipeline (حلقهٔ بستهٔ فرصت → انتشار).
 *
 * جریان:
 *   opportunities (status=open) → اولویت‌بندی → BulkJob + BulkJobItems
 *   → BulkContentService::processItem (تولید + گیت کیفیت + انتشار خودکار)
 *
 * هر فرصت فقط یک‌بار به دسته تبدیل می‌شود (idempotent — چک تکراری با keyword+site_id).
 * سطح خودمختاری سایت تعیین می‌کند آیا انتشار خودکار فعال باشد (auto_publish).
 */
class OpportunityPipeline
{
    /** حداکثر فرصت در هر اجرای pipeline (جلوگیری از سیل آیتم) */
    public const MAX_ITEMS_PER_RUN = 10;

    /** حداقل امتیاز فرصت برای ورود به pipeline */
    public const MIN_SCORE = 30;

    public function __construct(
        private readonly AdaptiveLearning $adaptiveLearning,
        private readonly DecisionEngine $decisionEngine,
    ) {}

    /**
     * اجرای اصلی pipeline — فرصت‌های باز را به دسته تولید گروهی تبدیل می‌کند.
     *
     * @return array{jobs_created: int, items_created: int, skipped: int, opportunities_processed: int}
     */
    public function handle(Site $site): array
    {
        // ۱) خواندن فرصت‌ها از هر دو منبع: opportunities + static_opportunities
        // ستون‌های مشترک: id, site_id, url_profile_id, type, score, confidence, status, explanation
        // static_opportunities اضافه: source, title, keyword_suggested, metadata
        // opportunities اضافه: keyword_insight_id

        $oppRows = DB::table('opportunities')
            ->where('site_id', $site->id)
            ->where('status', 'open')
            ->where('score', '>=', self::MIN_SCORE)
            ->select([
                'id', 'site_id', 'url_profile_id', 'type', 'score', 'confidence', 'status', 'explanation',
                DB::raw('null as keyword_suggested'),
                DB::raw('null as title'),
                DB::raw('null as metadata'),
                DB::raw('keyword_insight_id'),
            ])
            ->get();

        $staticRows = DB::table('static_opportunities')
            ->where('site_id', $site->id)
            ->where('status', 'open')
            ->where('score', '>=', self::MIN_SCORE)
            ->select([
                'id', 'site_id', 'url_profile_id', 'type', 'score', 'confidence', 'status', 'explanation',
                'keyword_suggested',
                'title',
                'metadata',
                DB::raw('null as keyword_insight_id'),
            ])
            ->get();

        $allOpportunities = $oppRows->concat($staticRows)->values();

        if ($allOpportunities->isEmpty()) {
            return ['jobs_created' => 0, 'items_created' => 0, 'skipped' => 0, 'opportunities_processed' => 0];
        }

        // ۱.۵) قدم ۲ اکوسیستم — DecisionEngine: تخصیص بودجهٔ هوشمند بر اساس اثر÷ریسک
        $dailyBudget = $this->dailyBudget($site->id);
        $allocation = $this->decisionEngine->allocate(
            (int) $site->id,
            $allOpportunities->all(),
            $dailyBudget,
        );
        $opportunities = collect($allocation['allocated'])
            ->map(fn (array $row): object => $row['opportunity'])
            ->take(self::MAX_ITEMS_PER_RUN)
            ->values();

        if ($opportunities->isEmpty()) {
            return ['jobs_created' => 0, 'items_created' => 0, 'skipped' => 0, 'opportunities_processed' => 0];
        }

        Log::info('OpportunityPipeline: allocation via DecisionEngine', [
            'site_id' => $site->id,
            'budget' => $allocation['budget'],
            'used' => $allocation['used'],
            'allocated' => count($allocation['allocated']),
            'deferred' => count($allocation['deferred']),
            'method' => $allocation['method'],
        ]);

        // ۲) تعیین سطح خودمختاری برای auto_publish
        $policy = DB::table('site_automation_policies')
            ->where('site_id', $site->id)
            ->first();
        $tier = $policy->autonomy_tier ?? 'manual';
        $autoPublish = $this->autoPublishForTier($tier);

        // ۳) گروه‌بندی فرصت‌ها بر اساس نوع
        $grouped = $opportunities->groupBy('type');

        $jobsCreated = 0;
        $itemsCreated = 0;
        $skipped = 0;

        foreach ($grouped as $type => $group) {
            // چک تکراری: آیا دسته‌ای با نام مشابه برای همین سایت قبلاً ساخته شده؟
            $jobName = $this->jobNameForType($type);
            $existingJob = DB::table('bulk_jobs')
                ->where('site_id', $site->id)
                ->where('name', $jobName)
                ->where('status', '!=', 'failed')
                ->first();

            if ($existingJob !== null) {
                // دسته موجود — فقط آیتم‌های جدید (کیووردهای جدید) را اضافه کن
                $existingKeywords = DB::table('bulk_job_items')
                    ->where('bulk_job_id', $existingJob->id)
                    ->pluck('keyword')
                    ->toArray();

                foreach ($group as $opp) {
                    $keyword = $this->deriveKeyword($opp, $site);
                    if ($keyword === '' || in_array($keyword, $existingKeywords, true)) {
                        $skipped++;
                        continue;
                    }
                    $this->createItem($existingJob->id, $opp, $keyword);
                    $itemsCreated++;
                }

                if ($itemsCreated > 0) {
                    DB::table('bulk_jobs')->where('id', $existingJob->id)->update([
                        'total_items' => DB::raw("(SELECT COUNT(*) FROM bulk_job_items WHERE bulk_job_id = {$existingJob->id})"),
                        'updated_at' => now(),
                    ]);
                }

                $jobsCreated++;
                continue;
            }

            // ۴) ساخت دسته جدید
            $jobId = (int) DB::table('bulk_jobs')->insertGetId([
                'organization_id' => (int) $site->organization_id,
                'site_id' => $site->id,
                'name' => $jobName,
                'content_type' => 'article',
                'subtype' => 'article',
                'status' => 'pending',
                'auto_publish' => $autoPublish,
                'created_by' => null, // خودکار
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($group as $opp) {
                $keyword = $this->deriveKeyword($opp, $site);
                if ($keyword === '') {
                    $skipped++;
                    continue;
                }
                $this->createItem($jobId, $opp, $keyword);
                $itemsCreated++;
            }

            // بروزرسانی شمارنده
            DB::table('bulk_jobs')->where('id', $jobId)->update([
                'total_items' => (int) DB::table('bulk_job_items')->where('bulk_job_id', $jobId)->count(),
            ]);

            // ۵) بستن فرصت‌های پردازش‌شده (هر دو جدول)
            $oppIds = $group->pluck('id')->toArray();
            DB::table('opportunities')
                ->whereIn('id', $oppIds)
                ->update(['status' => 'processed', 'updated_at' => now()]);
            DB::table('static_opportunities')
                ->whereIn('id', $oppIds)
                ->update(['status' => 'processed', 'updated_at' => now()]);

            $jobsCreated++;

            Log::info('OpportunityPipeline: job created', [
                'job_id' => $jobId,
                'site_id' => $site->id,
                'type' => $type,
                'items' => count($group),
                'auto_publish' => $autoPublish,
            ]);
        }

        return [
            'jobs_created' => $jobsCreated,
            'items_created' => $itemsCreated,
            'skipped' => $skipped,
            'opportunities_processed' => $opportunities->count(),
        ];
    }

    /**
     * بودجهٔ روزانهٔ اجرا — از پروفایل خودمختاری سایت (پیش‌فرض ۱۰).
     */
    private function dailyBudget(int $siteId): int
    {
        $policy = DB::table('site_automation_policies')
            ->where('site_id', $siteId)
            ->first();

        if ($policy === null) {
            return 10;
        }

        $budget = (int) ($policy->daily_command_limit ?? 10);

        return max(1, min(100, $budget));
    }

    /**
     * تبدیل یک فرصت به keyword مناسب برای تولید محتوا.
     */
    private function deriveKeyword(object $opp, Site $site): string
    {
        // ۱) اگر keyword_suggested مستقیم دارد (از کرالر یا هر منبع دیگه)
        if (! empty($opp->keyword_suggested)) {
            return $opp->keyword_suggested;
        }

        // ۲) اگر keyword_insight_id دارد، عبارت جستجو را برگردان
        if (isset($opp->keyword_insight_id) && $opp->keyword_insight_id !== null) {
            $insight = DB::table('keyword_insights')
                ->where('id', $opp->keyword_insight_id)
                ->first();
            if ($insight !== null && ! empty($insight->query_normalized)) {
                return $insight->query_normalized;
            }
        }

        // ۳) اگر url_profile_id دارد، slug صفحه را برگردان
        if (isset($opp->url_profile_id) && $opp->url_profile_id !== null) {
            $profile = DB::table('url_profiles')
                ->where('id', $opp->url_profile_id)
                ->first();
            if ($profile !== null && ! empty($profile->slug)) {
                return str_replace('-', ' ', $profile->slug);
            }
        }

        // ۴) fallback: عنوان از title
        if (! empty($opp->title)) {
            return $opp->title;
        }

        return '';
    }

    /**
     * ساخت نام دسته بر اساس نوع فرصت.
     */
    private function jobNameForType(string $type): string
    {
        return match ($type) {
            'ctr_gap' => 'بهینه‌سازی CTR — فرصت خودکار',
            'keyword_opportunity' => 'تولید محتوای هدفمند — فرصت خودکار',
            'conversion_boost' => 'افزایش تبدیل — فرصت خودکار',
            'content_gap' => 'تقویت محتوای کم‌عمق — فرصت خودکار',
            'meta_optimization' => 'بهینه‌سازی متادیتا — فرصت خودکار',
            'schema_gap' => 'تکمیل اسکیما — فرصت خودکار',
            'accessibility' => 'بهبود دسترسی‌پذیری — فرصت خودکار',
            default => 'فرصت خودکار — '.$type,
        };
    }

    /**
     * تعیین auto_publish بر اساس سطح خودمختاری.
     */
    private function autoPublishForTier(string $tier): string
    {
        return match ($tier) {
            't2', 't3' => 'publish',
            't1' => 'draft',
            default => 'off', // manual — فقط تولید، بدون انتشار
        };
    }

    /**
     * ساخت یک آیتم در دسته تولید گروهی.
     */
    private function createItem(int $jobId, object $opp, string $keyword): void
    {
        DB::table('bulk_job_items')->insert([
            'bulk_job_id' => $jobId,
            'keyword' => $keyword,
            'title' => $keyword, // عنوان اولیه = کیوورد
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
