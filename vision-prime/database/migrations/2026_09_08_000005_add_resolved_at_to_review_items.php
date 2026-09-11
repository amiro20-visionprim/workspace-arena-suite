<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ستون resolved_at برای review_items — TriageEngine و CollectPlatformEvents
 * به آن وابسته‌اند ولی در migration اصلی تعریف نشده بود (باگ production:
 * CollectPlatformEvents هر روز fail می‌شد).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('review_items', 'resolved_at')) {
            Schema::table('review_items', function (Blueprint $table): void {
                $table->timestamp('resolved_at')->nullable()->after('due_at');
                $table->index(['status', 'resolved_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('review_items', function (Blueprint $table): void {
            $table->dropIndex(['status', 'resolved_at']);
            $table->dropColumn('resolved_at');
        });
    }
};