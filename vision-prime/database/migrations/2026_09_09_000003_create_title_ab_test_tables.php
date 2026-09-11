<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // جدول تست‌های A/B عنوان
        Schema::create('title_ab_tests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('draft_id')->nullable();
            $table->string('original_title');
            $table->enum('status', ['draft', 'running', 'paused', 'completed', 'cancelled'])->default('draft');
            $table->integer('min_impressions')->default(100); // حداقل نمایش برای تصمیم‌گیری
            $table->integer('duration_days')->default(7); // مدت تست به روز
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('winner_variant_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')->onDelete('cascade');
        });

        // جدول متغیرهای (عنوان‌های) تست
        Schema::create('title_ab_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('test_id');
            $table->string('title');
            $table->integer('impressions')->default(0); // تعداد نمایش
            $table->integer('clicks')->default(0); // تعداد کلیک
            $table->decimal('ctr', 8, 4)->default(0); // نرخ کلیک
            $table->decimal('confidence', 5, 2)->default(0); // اطمینان آماری (%)
            $table->boolean('is_winner')->default(false);
            $table->boolean('is_original')->default(false); // آیا عنوان اصلیه؟
            $table->timestamps();

            $table->foreign('test_id')->references('id')->on('title_ab_tests')->onDelete('cascade');
        });

        // جدول رویدادهای ردیابی ( impression + click )
        Schema::create('title_ab_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('variant_id');
            $table->enum('event_type', ['impression', 'click']);
            $table->string('visitor_hash', 32)->nullable(); // هش بازدیدکننده (برای جلوگیری از تقلب)
            $table->string('url', 2048)->nullable(); // صفحه‌ای که رویداد در اون رخ داده
            $table->string('referrer', 2048)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['variant_id', 'event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('title_ab_events');
        Schema::dropIfExists('title_ab_variants');
        Schema::dropIfExists('title_ab_tests');
    }
};
