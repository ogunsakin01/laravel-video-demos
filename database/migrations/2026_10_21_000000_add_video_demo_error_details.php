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
        // The full error behind a failed video: its stack trace, the page and the app's own exceptions.
        Schema::table('video_demo_videos', function (Blueprint $table) {
            $table->json('error_details')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_demo_videos', function (Blueprint $table) {
            $table->dropColumn('error_details');
        });
    }
};
