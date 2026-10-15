<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The code a video was planned from, and the last check that its plan still works.
        Schema::table('video_demo_videos', function (Blueprint $table) {
            $table->json('source_files')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->json('verify_result')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_demo_videos', function (Blueprint $table) {
            $table->dropColumn(['source_files', 'verified_at', 'verify_result']);
        });
    }
};
