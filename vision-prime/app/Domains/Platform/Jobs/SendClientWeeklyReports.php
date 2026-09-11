<?php

declare(strict_types=1);

namespace App\Domains\Platform\Jobs;

use App\Domains\Platform\Services\PlatformNotifier;
use App\Domains\Reporting\Actions\BuildClientWeeklyReport;
use App\Domains\Workspace\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * قدم ۵ اکوسیستم — گزارش هفتگی خودکار مشتری.
 *
 * هر شنبه صبح اجرا می‌شود:
 *   1. برای هر مشتری با سایت فعال → BuildClientWeeklyReport
 *   2. گزارش در جدول reports ذخیره می‌شود (status=published)
 *   3. اعلان در پنل مشتری + پیام رسان (تلگرام اگر متصل باشد)
 *
 * در schedule:
 *   Schedule::job(new SendClientWeeklyReports)->weeklyOn(6, '09:00')
 */
class SendClientWeeklyReports implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public function handle(BuildClientWeeklyReport $builder): void
    {
        $clients = Client::query()
            ->whereHas('projects', fn ($q) => $q->has('sites'))
            ->with('projects.sites')
            ->get();

        if ($clients->isEmpty()) {
            Log::info('SendClientWeeklyReports: no clients with active sites');

            return;
        }

        $sent = 0;
        $failed = 0;

        foreach ($clients as $client) {
            try {
                $result = $builder->handle($client);

                // انتشار گزارش
                DB::table('reports')
                    ->where('id', $result['report_id'])
                    ->update([
                        'status' => 'published',
                        'published_at' => now(),
                        'updated_at' => now(),
                    ]);

                $sent++;

                Log::info('SendClientWeeklyReports: report generated', [
                    'client_id' => $client->getKey(),
                    'client_name' => $client->name,
                    'report_id' => $result['report_id'],
                    'highlights' => $result['summary']['highlights'] ?? [],
                ]);
            } catch (\Throwable $e) {
                $failed++;
                Log::error('SendClientWeeklyReports: client failed', [
                    'client_id' => $client->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // اطلاع‌رسانی به مدیر ارشد
        if ($sent > 0) {
            app(PlatformNotifier::class)->notify(
                "📊 گزارش هفتگی {$sent} مشتری با موفقیت تولید و منتشر شد".
                ($failed > 0 ? " ({$failed} ناموفق)" : ''),
                $failed > 0 ? 'warning' : 'info',
            );
        }

        Log::info('SendClientWeeklyReports: completed', [
            'total_clients' => $clients->count(),
            'sent' => $sent,
            'failed' => $failed,
        ]);
    }
}
