<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Content\Models\BulkJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * P2.8 — زمان‌بند دسته‌های تولید گروهی.
 *
 * دسته‌هایی که scheduled_at آن‌ها رسیده و هنوز پردازش نشده‌اند را در پس‌زمینه
 * اجرا می‌کند. برای cron (هر ۵ دقیقه) مناسب است:
 *
 *   * * * * * cd /var/www/... && php artisan content:bulk-schedule >> /dev/null 2>&1
 *
 * هر دسته فقط یک بار spawn می‌شود (چک started_at).
 */
class RunScheduledBulkJobs extends Command
{
    protected $signature = 'content:bulk-schedule {--limit=10 : حداکثر تعداد دسته در هر اجرا}';

    protected $description = 'اجرای خودکار دسته‌های زمان‌بندی‌شدهٔ تولید گروهی';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $jobs = BulkJob::query()
            ->where('status', 'pending')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->whereNull('started_at')
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get();

        if ($jobs->isEmpty()) {
            $this->info('دسته‌ای برای اجرای زمان‌بندی‌شده آماده نیست.');

            return self::SUCCESS;
        }

        foreach ($jobs as $job) {
            $artisan = base_path('artisan');
            $php = PHP_BINARY;
            $this->info("⚡ اجرای دسته #{$job->id} «{$job->name}»");

            try {
                $process = Process::timeout(0)
                    ->path(base_path())
                    ->start("\"{$php}\" \"{$artisan}\" content:bulk-run --job={$job->id} > /dev/null 2>&1 &");
                $this->line('   spawn: ok');
            } catch (\Throwable $e) {
                // Fallback: اجرای مستقیم هم‌زمان
                \Artisan::call('content:bulk-run', ['--job' => $job->id]);
                $this->line('   spawn: failed → اجرای مستقیم');
            }
        }

        return self::SUCCESS;
    }
}