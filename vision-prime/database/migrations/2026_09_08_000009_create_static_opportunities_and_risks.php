<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // فرصت‌های استاتیک که 내부 کرالر 탐지
        Schema::create('static_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('url_profile_id')->nullable()->constrained('url_profiles')->nullOnDelete();
            $table->string('type');              // keyword_opportunity | content_gap | interlink_opportunity
            $table->string('source');           // crawler_h2_pattern | crawler_thin_content | crawler_missing_alt
            $table->string('title')->nullable();
            $table->string('keyword_suggested')->nullable();
            $table->integer('score')->nullable();
            $table->float('confidence', 4)->nullable();
            $table->string('status')->default('open'); // open | closed | suppressed
            $table->text('explanation')->nullable();
            $table->json('metadata')->nullable();  // 변화를 trace
            $table->timestamps();

            $table->index(['site_id', 'type', 'status']);
            $table->index(['site_id', 'status']);
            $table->unique(['site_id', 'url_profile_id', 'type', 'source'], 'u_site_url_type_source');
        });

        // خطرات کیفیت 페이지 (thin content 등)
        Schema::create('static_risk_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('url_profile_id')->nullable()->constrained('url_profiles')->nullOnDelete();
            $table->string('key');              // thin_content | missing_meta_description | images_without_alt | multiple_h1 | missing_alt
            $table->string('severity')->default('low'); // low | medium | high
            $table->string('status')->default('open');  // open | resolved
            $table->text('explanation')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'key', 'status']);
            $table->unique(['site_id', 'url_profile_id', 'key'], 'u_site_url_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('static_risk_patterns');
        Schema::dropIfExists('static_opportunities');
    }
};
