<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('title_ab_tests', function (Blueprint $table) {
            $table->boolean('auto_update')->default(false)->after('duration_days');
            $table->unsignedBigInteger('wordpress_post_id')->nullable()->after('auto_update');
        });
    }

    public function down(): void
    {
        Schema::table('title_ab_tests', function (Blueprint $table) {
            $table->dropColumn(['auto_update', 'wordpress_post_id']);
        });
    }
};
