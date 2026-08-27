<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Domains\Audit\Actions\RecordAuditLog;
use App\Domains\Platform\Services\PlatformSettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * مدیریت بکاپ از پنل سوپرادمین: تنظیمات (ایمیل اطلاع‌رسانی، نگه‌داری، فعال/غیرفعال)،
 * اجرای دستی، فهرست فایل‌ها و دانلود آخرین بکاپ.
 */
class PlatformBackupController extends Controller
{
    public function __construct(
        private readonly PlatformSettingsService $settings,
    ) {}

    public function index(): Response
    {
        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir);

        $files = collect(File::files($dir))
            ->sortByDesc(fn ($f): int => $f->getMTime())
            ->values()
            ->map(fn ($f): array => [
                'name' => $f->getFilename(),
                'size_kb' => (int) round($f->getSize() / 1024, 1),
                'created_at' => date('Y-m-d H:i', $f->getMTime()),
            ])
            ->all();

        $lastRun = \DB::table('audit_logs')
            ->where('action', 'platform.backup.run')
            ->latest('occurred_at')
            ->first();

        return Inertia::render('Platform/Backups', [
            'settings' => [
                'enabled' => $this->settings->bool('backup_enabled', true),
                'notify_email' => (string) $this->settings->get('backup_notify_email', ''),
                'keep_days' => (int) $this->settings->get('backup_keep_days', 14),
            ],
            'files' => $files,
            'lastRun' => $lastRun === null ? null : [
                'at' => (string) $lastRun->occurred_at,
                'ok' => (bool) (json_decode((string) $lastRun->after, true)['ok'] ?? false),
                'file' => (string) (json_decode((string) $lastRun->after, true)['file'] ?? ''),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'notify_email' => ['nullable', 'email', 'max:200'],
            'keep_days' => ['required', 'integer', 'between:1,180'],
            'enabled' => ['required', 'boolean'],
        ], [
            'notify_email.email' => 'ایمیل اطلاع‌رسانی معتبر نیست.',
            'keep_days.between' => 'نگه‌داری بین ۱ تا ۱۸۰ روز.',
        ]);

        $this->settings->set('backup_notify_email', $data['notify_email'] ?? '');
        $this->settings->set('backup_keep_days', (int) $data['keep_days']);
        $this->settings->set('backup_enabled', $data['enabled'] ? 'true' : 'false');

        app(RecordAuditLog::class)->handle(
            action: 'platform.backup.settings_updated',
            after: ['notify_email' => $data['notify_email'] ?? '', 'keep_days' => (int) $data['keep_days'], 'enabled' => (bool) $data['enabled']],
        );

        return back()->with('status', 'تنظیمات بکاپ ذخیره شد.');
    }

    public function runNow(Request $request): RedirectResponse
    {
        Artisan::call('backup:run');
        $output = trim(Artisan::output());

        return back()->with(str_starts_with($output, '✅') ? 'status' : 'error', $output);
    }

    public function download(string $file): StreamedResponse
    {
        $path = storage_path('app/backups/'.basename($file));

        abort_unless(is_file($path), 404, 'فایل بکاپ یافت نشد.');

        return response()->download($path);
    }
}
