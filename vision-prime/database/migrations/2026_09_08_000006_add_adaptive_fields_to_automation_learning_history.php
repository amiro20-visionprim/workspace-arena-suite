<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قدم ۲ اکوسیستم (Adaptive) — ستون‌های موردنیاز موتور یادگیری تطبیقی:
 *  - consecutive_failures: تعداد شکست‌های متوالیِ اخیر (تا قبل از آخرین موفقیت)
 *  - blocked: آیا این نوع دستور برای این سایت موقتاً مسدود شده (دیگر پیشنهاد نمی‌شود)
 *  - blocked_reason: دلیل فارسی مسدودیت (شفاف برای کاربر و لاگ)
 *  - blocked_at: زمان مسدود شدن (برای auto-heal و گزارش)
 *  - last_status: آخرین نتیجهٔ واقعی (executed / rolled_back)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_learning_history', function (Blueprint $t): void {
            $t->unsignedInteger('consecutive_failures')->default(0)->after('successful');
            $t->boolean('blocked')->default(false)->after('consecutive_failures');
            $t->string('blocked_reason')->nullable()->after('blocked');
            $t->timestamp('blocked_at')->nullable()->after('blocked_reason');
            $t->string('last_status')->nullable()->after('blocked_at');
        });
    }

    public function down(): void
    {
        Schema::table('automation_learning_history', function (Blueprint $t): void {
            $t->dropColumn(['consecutive_failures', 'blocked', 'blocked_reason', 'blocked_at', 'last_status']);
        });
    }
};