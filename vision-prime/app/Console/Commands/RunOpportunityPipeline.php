<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Content\Services\OpportunityPipeline;
use App\Domains\Workspace\Models\Site;
use Illuminate\Console\Command;

/**
 * قدم ۴ اکوسیستم — کنسول کامند OpportunityPipeline.
 *
 * فرصت‌های باز هر سایت را به دسته تولید گروهی تبدیل می‌کند.
 * برای cron (هر ۳۰ دقیقه) مناسب است:
 *
 *   CRON: 0,30 * * * * cd /var/www/... && php artisan content:opportunity-pipeline >> /dev/null 2>&1
 */
class RunOpportunityPipeline extends Command
{
    protected $signature = 'content:opportunity-pipeline {--site= : شناسهٔ سایت خاص (پیش‌فرض: همهٔ سایتهای فعال)}';

    protected $description = 'تبدیل خودکار فرصت‌های باز به دسته تولید گروهی';

    public function handle(OpportunityPipeline $pipeline): int
    {
        $siteId = $this->option('site');

        $sites = $siteId
            ? Site::query()->where('id', $siteId)->where('status', 'active')->get()
            : Site::query()->where('status', 'active')->get();

        if ($sites->isEmpty()) {
            $this->warn('سایت فعالی یافت نشد.');

            return self::SUCCESS;
        }

        $totalJobs = 0;
        $totalItems = 0;

        foreach ($sites as $site) {
            $this->line("🔍 بررسی سایت «{$site->name}» (#{$site->id})...");

            try {
                $result = $pipeline->handle($site);
                $totalJobs += $result['jobs_created'];
                $totalItems += $result['items_created'];

                if ($result['jobs_created'] > 0) {
                    $this->info("   ✅ {$result['jobs_created']} دسته + {$result['items_created']} آیتم جدید");
                } else {
                    $this->line("   — فرصت جدیدی یافت نشد");
                }
            } catch (\Throwable $e) {
                $this->error("   ❌ خطا: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("📊 خلاصه: {$totalJobs} دسته | {$totalItems} آیتم | ".count($sites)." سایت بررسی شد");

        return self::SUCCESS;
    }
}
