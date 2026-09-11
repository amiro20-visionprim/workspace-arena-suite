<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قدم ۳ اکوسیستم — خودمختاری پلکانی (T1/T2/T3).
 *
 * ستون autonomy_tier روی سیاست خودکارسازی سایت:
 *  - manual (پیش‌فرض): رفتار فعلی — همه‌چیز با تأیید انسانی (backward-compat)
 *  - t1: تغییرات کم‌خطر (متا title/description) خودکار — بدون گرمایش
 *  - t2: + تولید محتوای جدید (مقاله/محصول) خودکار با گیت کیفیت + اطلاع‌رسانی
 *  - t3: + به‌روزرسانی محتوای منتشرشده خودکار با گیت‌های سخت‌گیرانه
 * انتشار/حذف/تغییر ساختار (R3) در همهٔ سطوح تأیید انسانی می‌ماند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_automation_policies', function (Blueprint $t): void {
            $t->string('autonomy_tier', 16)->default('manual')->after('level');
        });
    }

    public function down(): void
    {
        Schema::table('site_automation_policies', function (Blueprint $t): void {
            $t->dropColumn('autonomy_tier');
        });
    }
};