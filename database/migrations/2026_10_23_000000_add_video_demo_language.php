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
        // The language chosen for a video (null: the app's), and the one found on the app's pages.
        Schema::table('video_demo_videos', function (Blueprint $table) {
            $table->string('language', 20)->nullable();
            $table->string('page_language', 20)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_demo_videos', function (Blueprint $table) {
            $table->dropColumn(['language', 'page_language']);
        });
    }
};
