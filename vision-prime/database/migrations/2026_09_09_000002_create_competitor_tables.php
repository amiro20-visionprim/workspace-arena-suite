<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // جدول رقبا
        Schema::create('competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name');
            $table->string('url');
            $table->timestamp('crawled_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'url']);
        });

        // جدول صفحات رقبا
        Schema::create('competitor_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competitor_id')->constrained('competitors')->cascadeOnDelete();
            $table->string('url');
            $table->string('title')->nullable();
            $table->text('meta_description')->nullable();
            $table->integer('word_count')->default(0);
            $table->integer('h2_count')->default(0);
            $table->string('content_type')->nullable();
            $table->integer('internal_links')->default(0);
            $table->integer('image_count')->default(0);
            $table->boolean('has_schema')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('crawled_at');
            $table->timestamps();

            $table->unique(['competitor_id', 'url']);
            $table->index(['competitor_id', 'content_type']);
        });

        // جدول کلمات کلیدی رقبا
        Schema::create('competitor_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competitor_id')->constrained('competitors')->cascadeOnDelete();
            $table->string('keyword');
            $table->integer('occurrences')->default(1);
            $table->timestamps();

            $table->unique(['competitor_id', 'keyword']);
            $table->index(['competitor_id', 'occurrences']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_keywords');
        Schema::dropIfExists('competitor_pages');
        Schema::dropIfExists('competitors');
    }
};
