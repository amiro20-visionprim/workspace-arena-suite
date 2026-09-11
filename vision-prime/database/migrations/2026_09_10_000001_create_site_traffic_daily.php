<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قدم ۱ اکوسیستم — DataBridge: تلمتری داخلی ترافیک.
 *
 * وقتی دسترسی به Google Search Console قطع است (نت ملی / تحریم)، حلقهٔ
 * اندازه‌گیری تأثیر انتشار کور می‌ماند. این جدول دادهٔ ترافیک واقعی را
 * از خودِ سایت (پلاگین وردپرس یا Matomo سلف‌هاست) جمع می‌کند تا
 * BuildPublishImpactReport بتواند بدون GSC هم baseline/after بسازد.
 *
 * یک ردیف = یک URL × یک روز × یک منبع داده.
 * منبع‌ها: plugin (شمارش سمت سرور وردپرس)، matomo (سلف‌هاست)، gsc (در آینده از relay).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_traffic_daily', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('page_url', 768);
            $table->date('date')->index();
            $table->string('source', 20)->default('plugin')->index(); // plugin | matomo | gsc

            // متریک‌های سمت سایت (واقعی و بی‌واسطه)
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('visitors')->default(0); // تخمینی از cookie/session سمت سایت
            $table->unsignedInteger('entrances')->default(0); // ورود مستقیم به این صفحه

            // متریک‌های SERP در صورت وجود منبع خارجی (relay/Matomo search keywords)
            $table->unsignedInteger('search_impressions')->nullable();
            $table->unsignedInteger('search_clicks')->nullable();
            $table->decimal('avg_position', 6, 2)->nullable();

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['site_id', 'page_url', 'date', 'source'], 'uq_traffic_url_date_source');
            $table->index(['site_id', 'date']);
            $table->index(['site_id', 'page_url', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_traffic_daily');
    }
};
