<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Audit\Actions\RecordAuditLog;
use App\Domains\Platform\Services\PlatformSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/**
 * بکاپ کامل قابل مدیریت از پنل سوپرادمین:
 *   php artisan backup:run
 *
 *   - pgsql: pg_dump فشرده | دیگر درایورها: delegates به platform:backup-db
 *   - نگه‌داری بر اساس تنظیم backup_keep_days (پیش‌فرض ۱۴)
 *   - اطلاع‌رسانی ایمیلی به backup_notify_email در صورت موفقیت/شکست
 *   - رد حسابرسی: platform.backup.run
 */
class RunBackup extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'اجرای بکاپ دیتابیس + پاکسازی بر اساس نگه‌داری + اطلاع‌رسانی ایمیلی';

    public function handle(PlatformSettingsService $settings): int
    {
        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir);

        $keepDays = max(1, (int) $settings->get('backup_keep_days', 14));
        $notify = trim((string) $settings->get('backup_notify_email', ''));
        $file = null;
        $error = null;

        try {
            $connection = (string) config('database.default');
            $db = config("database.connections.{$connection}");

            if (($db['driver'] ?? '') === 'pgsql') {
                $name = 'db-'.now()->format('Ymd-His').'.sql.gz';
                $path = "{$dir}/{$name}";
                $cmd = sprintf(
                    'PGPASSWORD=%s pg_dump -h %s -p %s -U %s %s | gzip -f > %s',
                    escapeshellarg((string) ($db['password'] ?? '')),
                    escapeshellarg((string) ($db['host'] ?? '127.0.0.1')),
                    escapeshellarg((string) ($db['port'] ?? '5432')),
                    escapeshellarg((string) ($db['username'] ?? '')),
                    escapeshellarg((string) ($db['database'] ?? '')),
                    escapeshellarg($path),
                );
                exec($cmd.' 2>&1', $out, $code);
                if ($code !== 0 || ! is_file($path) || filesize($path) === 0) {
                    throw new \RuntimeException('pg_dump ناموفق: '.implode(' ', $out));
                }
                $file = $path;
            } else {
                Artisan::call('platform:backup-db', ['--keep' => $keepDays]);
                $candidates = collect(File::files(storage_path('backups')))
                    ->filter(fn ($f): bool => str_ends_with($f->getFilename(), '.sqlite'))
                    ->sortByDesc(fn ($f): int => $f->getMTime());
                if ($candidates->isNotEmpty()) {
                    $latest = $candidates->first();
                    $file = "{$dir}/db-".now()->format('Ymd-His').'.sqlite';
                    File::copy($latest->getPathname(), $file);
                } else {
                    throw new \RuntimeException('فایل بکاپ تولید نشد (خروجی: '.trim(Artisan::output()).')');
                }
            }

            // نگه‌داری
            foreach (File::files($dir) as $f) {
                if ($f->getMTime() < now()->subDays($keepDays)->getTimestamp()) {
                    @unlink($f->getPathname());
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $ok = $error === null;
        $size = $file !== null && is_file($file) ? (int) filesize($file) : 0;

        app(RecordAuditLog::class)->handle(
            action: 'platform.backup.run',
            after: ['ok' => $ok, 'file' => $file !== null ? basename($file) : null, 'size' => $size, 'error' => $error],
            source: 'system',
        );

        if ($notify !== '') {
            $body = $ok
                ? "بکاپ با موفقیت گرفته شد.\n\nفایل: ".basename((string) $file)."\nحجم: ".number_format($size / 1024, 1)." KB\nسرور: ".config('app.url')."\n\n— Vision Prime SUITE"
                : "بکاپ ناموفق بود!\n\nخطا: {$error}\nسرور: ".config('app.url');
            Mail::raw($body, function ($message) use ($notify, $ok): void {
                $message->to($notify)->subject($ok ? '✅ بکاپ Vision Prime موفق بود' : '🔴 بکاپ Vision Prime ناموفق بود');
            });
        }

        $ok ? $this->info('✅ بکاپ: '.basename((string) $file).' ('.number_format($size / 1024, 1).' KB)')
            : $this->error("❌ بکاپ ناموفق: {$error}");

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
