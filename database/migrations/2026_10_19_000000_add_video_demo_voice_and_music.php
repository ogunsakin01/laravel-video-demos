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
            // The voice-over, such as "kokoro:af_heart"; null for none.
            $table->string('voice')->nullable();
            // The background music track's key; null for none.
            $table->string('music')->nullable();
            // How loud the music is, from 0 to 1.
            $table->decimal('music_volume', 3, 2)->nullable();
        });

        Schema::table('video_demo_styles', function (Blueprint $table) {
            // A style's default voice and music, which new videos in it start with.
            $table->string('voice')->nullable();
            $table->string('music')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_demo_videos', function (Blueprint $table) {
            $table->dropColumn(['voice', 'music', 'music_volume']);
        });

        Schema::table('video_demo_styles', function (Blueprint $table) {
            $table->dropColumn(['voice', 'music']);
        });
    }
};
