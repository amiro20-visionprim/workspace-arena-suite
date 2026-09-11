<?php

declare(strict_types=1);

namespace App\Domains\Seo\Jobs;

use App\Domains\Seo\Services\SiteAnalysisService;
use App\Domains\Seo\Services\SiteSpiderService;
use App\Domains\Workspace\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * راهکار ۲ — SpiderRun: اجرا هر شب (حتی بدون GSC).
 *
 * هر밤:
 *   crawl sited },
 *   upsert url_profiles,
 *   detect quality gaps & keyword opportunities,
 *   sync static_opportunities to main system.
 *
 * Schedule: هر شب ۰۳:۰۰ (بعد از هرکاری که ممکن است cuerpo crawl)
 */
class SpiderRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;

    public function handle(SiteSpiderService $spider, SiteAnalysisService $analyzer): void
    {
        $sites = Site::query()->where('status', 'active')->limit(20)->get();

        foreach ($sites as $site) {
            try {
                // مرحله ۱: کرال
                $result = $spider->crawl($site);

                $logContext = array_merge($result, ['site_id' => $site->id, 'site_name' => $site->name]);

                if ($result['pages_crawled'] > 0) {
                    Log::info('SpiderRunJob: site crawled', $logContext);
                } else {
                    Log::notice('SpiderRunJob: no pages crawled', $logContext);
                }

                // sync static opportunities (after crawl)
                $staticCount = $spider->syncStaticOpportunities($site);
                Log::info('SpiderRunJob: static opportunities synced', [
                    'site_id' => $site->id, 'static_count' => $staticCount,
                ]);

                // مرحله ۲: تحلیل عمیق (گراف لینک + عمق + تازگی + تراکم کلیدواژه)
                if ($result['pages_crawled'] > 0) {
                    $analysis = $analyzer->analyze($site);
                    Log::info('SpiderRunJob: deep analysis completed', array_merge($analysis, ['site_id' => $site->id]));
                }
            } catch (\Throwable $e) {
                Log::error('SpiderRunJob: site failed', [
                    'site_id' => $site->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('SpiderRunJob: completed', ['sites_count' => $sites->count()]);
    }
}
