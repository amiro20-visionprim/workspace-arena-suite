<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Content\Models\BulkJob;
use App\Domains\Content\Services\BulkContentService;
use Illuminate\Console\Command;

/**
 * P2.5 — Worker تولید گروهی.
 *
 * Usage:
 *   php artisan content:bulk-run --job=12          پردازش یک دسته
 *   php artisan content:bulk-run --job=12 --limit=2  فقط ۲ آیتم (برای تست/ادامه)
 *
 * روی سرور بدون Supervisor هم کار می‌کند: فرانت این command را spawn می‌کند
 * یا اپراتور آن را به‌صورت دستی/کرون اجرا می‌کند.
 */
class RunBulkContentJob extends Command
{
    protected $signature = 'content:bulk-run
        {--job= : ID دسته تولید (اجباری)}
        {--limit=0 : حداکثر تعداد آیتم در این اجرا (0 = همه)}';

    protected $description = 'پردازش آیتم‌های یک دسته تولید گروهی محتوا';

    public function handle(BulkContentService $service): int
    {
        $jobId = (int) $this->option('job');
        if ($jobId === 0) {
            $this->error('--job الزامی است');

            return self::FAILURE;
        }

        $job = BulkJob::find($jobId);
        if ($job === null) {
            $this->error("دسته #{$jobId} یافت نشد");

            return self::FAILURE;
        }

        // P2.8 — گیت زمان‌بندی: قبل از زمان مقرر پردازش نمی‌شود
        if ($job->scheduled_at !== null && $job->scheduled_at->isFuture()) {
            $this->warn("⏳ زمان‌بندی شده برای {$job->scheduled_at->format('Y/m/d H:i')} — هنوز زمان آن نرسیده است.");

            return self::SUCCESS;
        }

        $this->info("⚙️ پردازش دسته «{$job->name}» (#{$job->id}) — {$job->total_items} آیتم");

        // آیتم‌های ناموفق هم قابل پردازش مجدد هستند (ری‌ترای)
        $job->items()->where('status', 'failed')->update(['status' => 'pending', 'error' => null]);

        $items = $job->items()
            ->whereIn('status', ['pending', 'processing'])
            ->orderBy('id')
            ->get();

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $items = $items->take($limit);
        }

        if ($items->isEmpty()) {
            $this->info('آیتمی برای پردازش باقی نمانده — به‌روزرسانی وضعیت نهایی');
            $service->syncJobProgress($job);
            $this->info("وضعیت نهایی: {$job->status} (موفق: {$job->completed_items} | نیازمند بازبینی: {$job->needs_review_items} | ناموفق: {$job->failed_items})");

            return self::SUCCESS;
        }

        if ($job->status === 'pending') {
            $job->update(['status' => 'running', 'started_at' => $job->started_at ?? now()]);
        }

        foreach ($items as $item) {
            $this->line("  ─ آیتم #{$item->id}: {$item->keyword}");
            $result = $service->processItem($item);
            $badge = match ($result['status']) {
                'completed' => '✅',
                'needs_review' => '🟡',
                'failed' => '❌',
                default => '⏳',
            };
            $detail = $result['status'] === 'failed' ? ': '.($result['error'] ?? '') : '';
            $this->info("  {$badge} {$result['status']}{$detail}");
        }

        $service->syncJobProgress($job);
        $this->newLine();
        $this->info("وضعیت: {$job->status} — موفق: {$job->completed_items} | نیازمند بازبینی: {$job->needs_review_items} | ناموفق: {$job->failed_items}");

        return self::SUCCESS;
    }
}