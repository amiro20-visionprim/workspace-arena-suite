<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ab_test_publish_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('test_id');
            $table->unsignedBigInteger('variant_id');
            $table->unsignedBigInteger('command_id')->nullable();
            $table->unsignedBigInteger('post_id');
            $table->string('old_title');
            $table->string('new_title');
            $table->enum('status', ['pending', 'dispatched', 'executed', 'failed'])->default('pending');
            $table->json('result')->nullable();
            $table->timestamps();

            $table->foreign('test_id')->references('id')->on('title_ab_tests')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ab_test_publish_log');
    }
};
