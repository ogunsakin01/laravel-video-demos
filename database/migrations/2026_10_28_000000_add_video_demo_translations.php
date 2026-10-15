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
        // A video in another language is a video of its own, made from the original's plan.
        Schema::table('video_demo_videos', function (Blueprint $table) {
            $table->unsignedBigInteger('source_video_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_demo_videos', function (Blueprint $table) {
            $table->dropIndex(['source_video_id']);
            $table->dropColumn('source_video_id');
        });
    }
};
