<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop old unique constraint and add new one with keyword_suggested
        Schema::table('static_opportunities', function (Blueprint $table) {
            $table->dropUnique('u_site_url_type_source');
            $table->unique(
                ['site_id', 'url_profile_id', 'type', 'source', 'keyword_suggested'],
                'u_site_url_type_source_kw'
            );
        });
    }

    public function down(): void
    {
        Schema::table('static_opportunities', function (Blueprint $table) {
            $table->dropUnique('u_site_url_type_source_kw');
            $table->unique(
                ['site_id', 'url_profile_id', 'type', 'source'],
                'u_site_url_type_source'
            );
        });
    }
};
