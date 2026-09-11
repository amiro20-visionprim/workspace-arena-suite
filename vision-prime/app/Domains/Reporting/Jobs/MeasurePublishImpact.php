<?php

declare(strict_types=1);

namespace App\Domains\Reporting\Jobs;

use App\Domains\Reporting\Actions\BuildPublishImpactReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * اندازهگیری خودکار تأثیر انتشار (قلب حلقهٔ تصمیم).
 *
 * روزانه اجرا می‌شود:
 *   1. کامندهای publish_new_article اجراشده‌ای که هنوز impact_event ندارند
 *      یا impact_event آن‌ها بیش از ۷ روز پیش ثبت شده و ممکن است دادهٔ GSC جدیدتری رسیده باشد
 *   2. BuildPublishImpactReport برای هر کامند اجرا می‌شود
 *   3. نتیجه در impact_events ذخیره/آپدیت می‌شود
 *   4. ریسک افت معیارها → هشدار خودکار
 *
 * در schedule:
 *   Schedule::job(new MeasurePublishImpact)->dailyAt('06:30')
 */
class MeasurePublishImpact implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public function handle(BuildPublishImpactReport $reportBuilder): void
    {
        // کامندهای publish_new_article که اجرا شده‌اند و published_at دارند
        // و یا impact_event ندارند یا آخرین impact_event بیش از ۷ روز پیش است
        $commands = DB::table('commands')
            ->where('type', 'publish_new_article')
            ->whereIn('status', ['executed', 'rolled_back'])
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now()->subDays(2)) // حداقل ۲ روز از انتشار گذشته باشد (تأخیر GSC)
            ->whereNotIn('id', function ($q) {
                $q->select('source_id')
                    ->from('impact_events')
                    ->where('source_type', 'command')
                    ->where('observed_at', '>=', now()->subDays(7));
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        if ($commands->isEmpty()) {
            Log::info('MeasurePublishImpact: no commands to measure');

            return;
        }

        $measured = 0;
        $insufficient = 0;
        $declines = 0;

        foreach ($commands as $command) {
            try {
                $report = $reportBuilder->handle($command);

                if (($report['status'] ?? '') !== 'ready') {
                    $insufficient++;
                    continue;
                }

                // ذخیره/آپدیت impact_event
                $siteId = (int) $command->site_id;
                $existingEvent = DB::table('impact_events')
                    ->where('site_id', $siteId)
                    ->where('source_type', 'command')
                    ->where('source_id', $command->id)
                    ->latest('observed_at')
                    ->first();

                // DataBridge: گزارش تلمتری (source=telemetry) دلتاها را به قرارداد کلیک/جایگاه
                // نگاشت می‌کنیم — views≡clicks و position ندارد (0 می‌ماند).
                $src = (string) ($report['source'] ?? 'gsc');
                $deltaClicks = (int) ($report['delta']['clicks'] ?? $report['delta']['views'] ?? 0);
                $deltaPosition = (float) ($report['delta']['position'] ?? 0);
                $noteMetric = $src === 'telemetry' ? 'بازدید' : 'کلیک';

                $eventData = [
                    'site_id' => $siteId,
                    'source_type' => 'command',
                    'source_id' => $command->id,
                    'baseline' => json_encode($report['before'] + ['source' => $src], JSON_UNESCAPED_UNICODE),
                    'attribution_note' => sprintf(
                        'تأثیر خودکار (%s): %s | %s: %+d',
                        $src === 'telemetry' ? 'تلمتری داخلی' : 'GSC',
                        $report['verdict'],
                        $noteMetric,
                        $deltaClicks,
                    ),
                    'observed_at' => now(),
                    'updated_at' => now(),
                ];

                if ($existingEvent !== null) {
                    DB::table('impact_events')
                        ->where('id', $existingEvent->id)
                        ->update($eventData + ['updated_at' => now()]);
                } else {
                    DB::table('impact_events')->insert($eventData + ['created_at' => now()]);
                }

                $measured++;

                if ($report['verdict'] === 'declined') {
                    $declines++;
                    Log::warning('MeasurePublishImpact: decline detected', [
                        'command_id' => $command->id,
                        'url' => $report['url'],
                        'source' => $src,
                        'delta_clicks' => $deltaClicks,
                        'delta_position' => $deltaPosition,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('MeasurePublishImpact: command failed', [
                    'command_id' => $command->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('MeasurePublishImpact: completed', [
            'measured' => $measured,
            'insufficient_data' => $insufficient,
            'declines' => $declines,
        ]);
    }
}
