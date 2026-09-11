<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لاگ batchهای تلمتری برای idempotency (retry پلاگین دوباره جمع نمی‌شود).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_traffic_ingest_log', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('batch_id', 64)->index();
            $table->string('source', 20)->default('plugin');
            $table->unsignedInteger('rows')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->unique(['site_id', 'batch_id'], 'uq_traffic_ingest_batch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_traffic_ingest_log');
    }
};
