<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2.6 — اتصال تولید گروهی به انتشار وردپرس.
 *
 * ستون‌های انتشار برای هر آیتم: وضعیت انتشار، شناسه پست وردپرس، زمان و خطا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_job_items', function (Blueprint $table) {
            $table->string('publish_status', 20)->nullable()->after('status'); // pending|publishing|published|failed
            $table->unsignedBigInteger('post_id')->nullable()->after('draft_id');
            $table->timestamp('published_at')->nullable()->after('finished_at');
            $table->text('publish_error')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_job_items', function (Blueprint $table) {
            $table->dropColumn(['publish_status', 'post_id', 'published_at', 'publish_error']);
        });
    }
};