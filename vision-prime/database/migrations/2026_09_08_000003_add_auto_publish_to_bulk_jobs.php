<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2.7 — انتشار خودکار تولید گروهی.
 *
 * auto_publish روی دستهٔ تولید: off (پیش‌فرض) | draft | publish
 * وقتی فعال باشد، آیتم‌هایی که از گیت کیفیت عبور می‌کنند بلافاصله به وردپرس
 * ارسال می‌شوند؛ آیتم‌های needs_review همیشه در صف بازبینی می‌مانند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_jobs', function (Blueprint $table) {
            $table->string('auto_publish', 10)->default('off')->after('subtype');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_jobs', function (Blueprint $table) {
            $table->dropColumn('auto_publish');
        });
    }
};