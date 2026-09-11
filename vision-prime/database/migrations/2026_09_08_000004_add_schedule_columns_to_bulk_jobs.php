<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2.8 — زمان‌بندی تولید گروهی.
 *
 * scheduled_at:      زمان شروع پردازش دسته (قبل از آن worker اجرا نمی‌شود)
 * daily_publish_limit: سقف انتشار خودکار روزانه (۰ = بدون محدودیت؛ بقیه در صف می‌مانند)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_jobs', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('auto_publish');
            $table->unsignedInteger('daily_publish_limit')->default(0)->after('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_jobs', function (Blueprint $table) {
            $table->dropColumn(['scheduled_at', 'daily_publish_limit']);
        });
    }
};