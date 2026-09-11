<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2.5 — تولید گروهی محتوا.
 *
 * bulk_jobs: یک «دسته تولید» (مثلاً ۵ مقاله برای کیووردهای مشخص)
 * bulk_job_items: هر آیتم یک کیوورد/عنوان که از کل pipeline تولید می‌گذرد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('site_id');
            $table->string('name')->nullable();
            $table->string('content_type', 30)->default('article');
            $table->string('subtype', 50)->nullable();
            $table->string('status', 20)->default('pending'); // pending|running|completed|partial|failed
            $table->integer('total_items')->default(0);
            $table->integer('completed_items')->default(0);
            $table->integer('failed_items')->default(0);
            $table->integer('needs_review_items')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('site_id');
        });

        Schema::create('bulk_job_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bulk_job_id');
            $table->string('keyword');
            $table->string('title')->nullable();
            $table->string('status', 20)->default('pending'); // pending|processing|completed|failed|needs_review
            $table->string('source', 20)->nullable(); // rule_based|ai
            $table->unsignedBigInteger('draft_id')->nullable();
            $table->integer('quality_score')->nullable();
            $table->integer('word_count')->nullable();
            $table->string('slug', 100)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['bulk_job_id', 'status']);
            $table->foreign('bulk_job_id')->references('id')->on('bulk_jobs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_job_items');
        Schema::dropIfExists('bulk_jobs');
    }
};