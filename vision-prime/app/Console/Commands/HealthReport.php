<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * گزارش سلامت عملیاتی — بدون هیچ وابستگیٔ خارجی (F1/F3 مونیتورینگ پایه).
 *
 *   php artisan app:health          → گزارش + خروج 1 اگر بحرانی باشد (برای alert)
 *
 * زمان‌بندی: هر روز ۸ صبح (routes/console.php) — خروجی در لاگ ذخیره می‌شود.
 */
class HealthReport extends Command
{
    protected $signature = 'app:health';

    protected $description = 'گزارش سلامت عملیاتی: صف، کارهای شکست‌خورده، خطاها، دیسک، بکاپ';

    public function handle(): int
    {
        $queueSize = (int) DB::table('jobs')->count();
        $failedJobs = (int) DB::table('failed_jobs')->count();
        $pendingReviews = (int) DB::table('review_items')->where('status', 'pending_review')->count();
        $scheduledCommands = (int) DB::table('commands')->where('status', 'scheduled')->count();
        $usersToday = 0;
        try {
            $usersToday = (int) DB::table('users')->whereDate('last_seen_at', today())->count();
        } catch (\Throwable) {
            // ستون last_seen_at هنوز مهاجرت نشده — صفر گزارش می‌شود نه خطا
        }

        // فاز D: مصرف و هزینهٔ تخمینی تصاویر این ماه
        $imagesThisMonth = 0;
        $imageCost = 0.0;
        try {
            $imagesThisMonth = (int) DB::table('media_assets')
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count();
            $imageCost = (float) DB::table('media_assets')
                ->where('source', 'ai')
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('cost_estimate');
        } catch (\Throwable) {
            // جداول تصویر هنوز مهاجرت نشده‌اند
        }
        $errorsToday = $this->errorCountToday();
        $diskFreePercent = $this->diskFreePercent();
        $lastBackupAge = $this->lastBackupAgeDays();

        $critical = $failedJobs > 0
            || $queueSize > 100
            || ($diskFreePercent !== null && $diskFreePercent < 10)
            || $errorsToday >= 50;

        $rows = [
            ['🧵 صف (jobs)', (string) $queueSize, $queueSize > 100 ? '⚠️ بیشتر از حد معمول' : 'سالم'],
            ['💀 کارهای شکست‌خورده', (string) $failedJobs, $failedJobs > 0 ? '🔴 نیاز به بررسی فوری' : 'سالم'],
            ['🔍 بازبینی‌های در انتظار', (string) $pendingReviews, 'اطلاعاتی'],
            ['⏰ فرمان‌های زمان‌بندی‌شده', (string) $scheduledCommands, 'اطلاعاتی'],
            ['👥 کاربران فعال امروز', (string) $usersToday, 'اطلاعاتی'],
            ['🚨 خطاهای امروز (log)', (string) $errorsToday, $errorsToday >= 50 ? '⚠️ نرمال نیست' : 'سالم'],
            ['💾 فضای آزاد دیسک', $diskFreePercent === null ? 'نامشخص' : $diskFreePercent.'%', $diskFreePercent !== null && $diskFreePercent < 10 ? '🔴 بحرانی' : 'سالم'],
            ['📦 آخرین بکاپ', $lastBackupAge === null ? 'هرگز!' : $lastBackupAge.' روز پیش', ($lastBackupAge === null || $lastBackupAge > 1) ? '⚠️ قدیمی/ناموجود' : 'سالم'],
            ['🖼️ تصاویر این ماه', (string) $imagesThisMonth, 'اطلاعاتی'],
            ['💵 هزینهٔ تخمینی AI تصویر', '$'.number_format($imageCost, 2), 'اطلاعاتی'],
        ];

        $this->table(['شاخص', 'مقدار', 'وضعیت'], $rows);

        $summary = sprintf(
            'health: queue=%d failed=%d reviews=%d scheduled=%d errors_today=%d disk_free=%s backup_age=%s => %s',
            $queueSize, $failedJobs, $pendingReviews, $scheduledCommands, $errorsToday,
            $diskFreePercent === null ? '?' : $diskFreePercent.'%',
            $lastBackupAge === null ? 'never' : $lastBackupAge.'d',
            $critical ? 'CRITICAL' : 'OK',
        );
        Log::channel('single')->info($summary);
        $this->line($critical ? '🔴 وضعیت: CRITICAL' : '🟢 وضعیت: OK');

        return $critical ? self::FAILURE : self::SUCCESS;
    }

    private function errorCountToday(): int
    {
        $path = storage_path('logs/laravel.log');

        if (! is_file($path)) {
            return 0;
        }

        $count = 0;
        $handle = fopen($path, 'r');
        $today = now()->toDateString();

        while (($line = fgets($handle)) !== false) {
            if (str_contains($line, 'ERROR') && str_contains($line, $today)) {
                $count++;
            }
        }
        fclose($handle);

        return $count;
    }

    private function diskFreePercent(): ?float
    {
        $total = @disk_total_space(storage_path());
        $free = @disk_free_space(storage_path());

        if ($total === false || $free === false || $total <= 0) {
            return null;
        }

        return round(($free / $total) * 100, 1);
    }

    private function lastBackupAgeDays(): ?int
    {
        $dirs = ['/var/backups/vision-prime', storage_path('backups')];

        $newest = 0;
        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach (scandir($dir) ?: [] as $file) {
                $m = @filemtime($dir.'/'.$file);
                if ($m !== false && $m > $newest) {
                    $newest = $m;
                }
            }
        }

        if ($newest === 0) {
            return null;
        }

        return (int) floor((time() - $newest) / 86400);
    }
}
