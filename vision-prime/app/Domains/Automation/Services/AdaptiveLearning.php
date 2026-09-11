<?php

declare(strict_types=1);

namespace App\Domains\Automation\Services;

use Illuminate\Support\Facades\DB;

/**
 * قدم ۲ اکوسیستم — موتور یادگیری تطبیقی (Adaptive Learning).
 *
 * حلقهٔ یادگیری (LearningLoop) هر روز نرخ موفقیت هر نوع دستور را به‌ازای هر سایت
 * در automation_learning_history می‌نویسد؛ این سرویس همان سابقه را به موتور
 * پیشنهاددهی وصل می‌کند:
 *
 *  - isBlocked(): اگر نوع دستوری روی سایتی شکست خورده، دیگر پیشنهاد داده نشود
 *  - history() / blockReason(): شفافیت برای UI، لاگ و گزارش‌ها
 *  - recovery: اگر بعد از مسدودیت دوباره موفقیت‌های پیاپی ثبت شود، به‌صورت
 *    خودکار unblock می‌شود (blocked فقط توسط LearningLoop تنظیم می‌شود).
 */
class AdaptiveLearning
{
    /** حداقل تعداد اجرا برای اعتماد به آمار */
    public const MIN_SAMPLE = 3;

    /** سقف نرخ موفقیت — زیر آن نوع دستور «ناموفق» در نظر گرفته می‌شود */
    public const SUCCESS_RATE_FLOOR = 0.5;

    /** چند شکست متوالی = مسدودیت فوری (حتی با نمونهٔ کم) */
    public const MAX_CONSECUTIVE_FAILURES = 3;

    /** چند موفقیت متوالی بعد از مسدودیت = رفع مسدودیت */
    public const RECOVERY_CONSECUTIVE_SUCCESSES = 2;

    /**
     * آیا این نوع دستور برای این سایت در وضعیت مسدود است؟
     */
    public function isBlocked(int $siteId, string $commandType): bool
    {
        return (bool) DB::table('automation_learning_history')
            ->where('site_id', $siteId)
            ->where('command_type', $commandType)
            ->value('blocked');
    }

    /**
     * دلیل مسدودیت (فارسی) یا null اگر مسدود نیست.
     */
    public function blockReason(int $siteId, string $commandType): ?string
    {
        $row = DB::table('automation_learning_history')
            ->where('site_id', $siteId)
            ->where('command_type', $commandType)
            ->first();

        if ($row === null || ! (bool) $row->blocked) {
            return null;
        }

        return $row->blocked_reason ?? 'نرخ موفقیت پایین این نوع تغییر؛ پیشنهاد آن متوقف شده است.';
    }

    /**
     * نمای کامل سلامت یادگیری برای یک نوع دستور (برای UI و گزارش‌ها).
     *
     * @return array{blocked: bool, blocked_reason: ?string, total: int, successful: int, consecutive_failures: int, success_rate: ?float, last_status: ?string, sample_size: int}
     */
    public function health(int $siteId, string $commandType): array
    {
        $row = DB::table('automation_learning_history')
            ->where('site_id', $siteId)
            ->where('command_type', $commandType)
            ->first();

        if ($row === null) {
            return [
                'blocked' => false,
                'blocked_reason' => null,
                'total' => 0,
                'successful' => 0,
                'consecutive_failures' => 0,
                'success_rate' => null,
                'last_status' => null,
                'sample_size' => 0,
            ];
        }

        $total = (int) $row->total;
        $successful = (int) $row->successful;

        return [
            'blocked' => (bool) $row->blocked,
            'blocked_reason' => $row->blocked_reason,
            'total' => $total,
            'successful' => $successful,
            'consecutive_failures' => (int) $row->consecutive_failures,
            'success_rate' => $total > 0 ? round($successful / $total, 3) : null,
            'last_status' => $row->last_status,
            'sample_size' => $total,
        ];
    }

    /**
     * آستانهٔ تصمیم‌گیری (قابل استفاده در تست‌ها): آیا این آمار باید مسدود شود؟
     *
     * @param  array{total: int, successful: int, consecutive_failures: int}  $stats
     */
    public static function shouldBlock(array $stats): bool
    {
        $total = (int) ($stats['total'] ?? 0);
        $successful = (int) ($stats['successful'] ?? 0);
        $consecutiveFailures = (int) ($stats['consecutive_failures'] ?? 0);

        // شکست‌های متوالی — مسدودیت فوری
        if ($consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
            return true;
        }

        // نرخ موفقیت پایین با نمونهٔ کافی
        if ($total >= self::MIN_SAMPLE && $successful / $total < self::SUCCESS_RATE_FLOOR) {
            return true;
        }

        return false;
    }
}