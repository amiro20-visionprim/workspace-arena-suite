<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ستون last_seen_at برای سنجش «کاربران فعال امروز» (app:health).
     * گارد hasColumn: اگر روی سروری ستون به‌صورت دستی اضافه شده باشد،
     * این migration بی‌صدا no-op می‌شود (بدون خطای duplicate column).
     */
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'last_seen_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('last_seen_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'last_seen_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('last_seen_at');
            });
        }
    }
};
