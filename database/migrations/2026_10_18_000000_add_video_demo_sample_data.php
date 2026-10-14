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
        Schema::table('video_demo_videos', function (Blueprint $table) {
            // Record against a temporary database filled with sample data, instead of the app's own.
            $table->boolean('sample_data')->default(false);
            // The records the AI added to that database, so recording again rebuilds the same data.
            $table->json('sample_records')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_demo_videos', function (Blueprint $table) {
            $table->dropColumn(['sample_data', 'sample_records']);
        });
    }
};
