<?php

declare(strict_types=1);

namespace App\Domains\Content\Jobs;

use App\Domains\Content\Services\OpportunityPipeline;
use App\Domains\Workspace\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * قدم ۴ اکوسیستم — Job اختصاصی OpportunityPipeline.
 *
 * این Job برای هر سایت فعال اجرا می‌شود و فرصت‌های باز را به دسته تولید گروهی تبدیل می‌کند.
 * در schedule هر ۳۰ دقیقه اجرا می‌شود.
 */
class OpportunityPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function handle(OpportunityPipeline $pipeline): void
    {
        $sites = Site::query()->where('status', 'active')->get();
        $totalJobs = 0;
        $totalItems = 0;

        foreach ($sites as $site) {
            try {
                $result = $pipeline->handle($site);
                $totalJobs += $result['jobs_created'];
                $totalItems += $result['items_created'];

                if ($result['jobs_created'] > 0) {
                    Log::info('OpportunityPipelineJob: site processed', [
                        'site_id' => $site->id,
                        'site_name' => $site->name,
                        'result' => $result,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('OpportunityPipelineJob: site failed', [
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('OpportunityPipelineJob: completed', [
            'sites_count' => $sites->count(),
            'total_jobs' => $totalJobs,
            'total_items' => $totalItems,
        ]);
    }
}
