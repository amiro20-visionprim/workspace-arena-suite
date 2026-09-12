<?php

declare(strict_types=1);

namespace App\Domains\Automation\Jobs;

use App\Domains\Automation\Services\AdaptiveLearning;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * حلقهٔ یادگیری (D-013 فاز ۳).
 *
 * هر روز از نتیجهٔ واقعی اجراها (executed = موفق، rolled_back = ناموفق) نرخ موفقیت
 * هر نوع تغییر را به‌ازای هر سایت محاسبه و در automation_learning_history می‌نویسد؛
 * موتور امتیازدهی در ساخت command بعدی از همین سابقه استفاده می‌کند.
 *
 * علاوه بر aggregation ساده، این job حالا:
 *  - consecutive_failures را شمارش می‌کند (شکست‌های متوالی از آخرین موفقیت)
 *  - blocked / blocked_reason را بر اساس آستانه‌های AdaptiveLearning ست می‌کند
 *  - auto-heal: بعد از RECOVERY_CONSECUTIVE_SUCCESSES موفقیت پیاپی، مسدودیت را برمی‌دارد
 *  - last_status را آخرین نتیجهٔ واقعی ثبت می‌کند
 */
class LearningLoop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(public ?int $siteId = null, public int $windowDays = 30) {}

    public function handle(): void
    {
        $since = now()->subDays($this->windowDays);

        $query = DB::table('commands')
            ->whereIn('status', ['executed', 'rolled_back'])
            ->where('created_at', '>=', $since);

        if ($this->siteId !== null) {
            $query->where('site_id', $this->siteId);
        }

        // ── Phase 1: aggregate total / successful ──
        $rows = $query
            ->selectRaw('site_id, type, COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as successful', ['executed'])
            ->groupBy('site_id', 'type')
            ->get();

        // ── Phase 2: per (site, type) compute consecutive_failures + last_status ──
        foreach ($rows as $row) {
            // Get all statuses for this (site, type) ordered oldest → newest
            $statuses = DB::table('commands')
                ->where('site_id', $row->site_id)
                ->where('type', $row->type)
                ->whereIn('status', ['executed', 'rolled_back'])
                ->where('created_at', '>=', $since)
                ->orderBy('id', 'asc')
                ->pluck('status')
                ->toArray();

            $lastStatus = end($statuses) ?: null;
            $consecutiveFailures = $this->countConsecutiveFailures($statuses);
            $total = (int) $row->total;
            $successful = (int) $row->successful;

            // ── Decision: block or unblock ──
            $blocked = false;
            $blockedReason = null;
            $blockedAt = null;

            // Check existing record for auto-heal
            $existing = DB::table('automation_learning_history')
                ->where('site_id', $row->site_id)
                ->where('command_type', $row->type)
                ->first();

            $wasBlocked = $existing && (bool) ($existing->blocked ?? false);

            if ($consecutiveFailures >= AdaptiveLearning::MAX_CONSECUTIVE_FAILURES) {
                $blocked = true;
                $blockedReason = "{$consecutiveFailures} شکست متوالی — این نوع تغییر موقتاً مسدود شد.";
                $blockedAt = now();
            } elseif ($total >= AdaptiveLearning::MIN_SAMPLE && $successful / $total < AdaptiveLearning::SUCCESS_RATE_FLOOR) {
                $blocked = true;
                $blockedReason = "نرخ موفقیت " . round(($successful / $total) * 100) . "٪ (زیر " . (AdaptiveLearning::SUCCESS_RATE_FLOOR * 100) . "٪) — پیشنهاد این نوع متوقف شد.";
                $blockedAt = now();
            } elseif ($wasBlocked) {
                // Was blocked — check for auto-heal
                $consecutiveSuccesses = $this->countConsecutiveSuccesses($statuses);
                if ($consecutiveSuccesses >= AdaptiveLearning::RECOVERY_CONSECUTIVE_SUCCESSES) {
                    // Auto-healed: enough consecutive successes
                    $blocked = false;
                    $blockedReason = null;
                    $blockedAt = null;
                } else {
                    // Still blocked — not enough recovery yet
                    $blocked = true;
                    $blockedReason = $existing->blocked_reason ?? 'هنوز در وضعیت مسدود.';
                    $blockedAt = $existing->blocked_at;
                }
            }

            DB::table('automation_learning_history')->updateOrInsert(
                ['site_id' => $row->site_id, 'command_type' => $row->type],
                [
                    'total' => $total,
                    'successful' => $successful,
                    'consecutive_failures' => $consecutiveFailures,
                    'blocked' => $blocked,
                    'blocked_reason' => $blockedReason,
                    'blocked_at' => $blockedAt,
                    'last_status' => $lastStatus,
                    'window_start' => $since,
                    'window_end' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    /**
     * شمارش شکست‌های متوالی از آخرین رکورد (قدیمی → جدید).
     */
    private function countConsecutiveFailures(array $statuses): int
    {
        $count = 0;
        for ($i = count($statuses) - 1; $i >= 0; $i--) {
            if ($statuses[$i] === 'rolled_back') {
                $count++;
            } else {
                break;
            }
        }

        return $count;
    }

    /**
     * شمارش موفقیت‌های متوالی از آخرین رکورد (برای auto-heal).
     */
    private function countConsecutiveSuccesses(array $statuses): int
    {
        $count = 0;
        for ($i = count($statuses) - 1; $i >= 0; $i--) {
            if ($statuses[$i] === 'executed') {
                $count++;
            } else {
                break;
            }
        }

        return $count;
    }
}
