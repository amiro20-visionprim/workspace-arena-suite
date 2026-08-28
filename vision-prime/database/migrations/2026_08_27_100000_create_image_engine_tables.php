<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // تنظیمات سرویس تصویر هر سازمان — همان الگوی ai_provider_settings
        Schema::create('image_provider_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            $table->text('encrypted_config');
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['organization_id', 'provider']);
        });

        // دارایی‌های تصویری: تولید AI / استوک / پیشنهاد — با سوئچ SEO و هزینه
        Schema::create('media_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('draft_id')->nullable()->constrained('content_drafts')->nullOnDelete();
            $table->string('source', 20)->default('stock'); // ai|stock|suggestion
            $table->string('provider', 50)->nullable();
            $table->text('url')->nullable();          // url استوک یا خروجی AI
            $table->string('path', 500)->nullable();   // فایل موقت روی پلتفرم (پیش از انتقال به وردپرس)
            $table->string('alt', 300)->nullable();
            $table->string('keywords', 300)->nullable();
            $table->string('credit', 200)->nullable(); // اعتبار عکاس استوک
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('slot', 20)->nullable();    // cover|section|gallery
            $table->unsignedInteger('section_index')->nullable();
            $table->decimal('cost_estimate', 10, 4)->default(0);
            $table->unsignedBigInteger('wp_media_id')->nullable(); // پس از آپلود به رسانهٔ وردپرس
            $table->text('wp_url')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('image_provider_settings');
    }
};
