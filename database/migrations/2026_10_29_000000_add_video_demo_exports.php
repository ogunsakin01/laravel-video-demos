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
        // A video's GIF, square and vertical copies, and the look styles add: a logo and an end card.
        Schema::table('video_demo_videos', function (Blueprint $table) {
            $table->json('exports')->nullable();
        });
        Schema::table('video_demo_styles', function (Blueprint $table) {
            $table->string('logo')->nullable();
            $table->string('logo_position')->default('none');
            $table->string('end_title')->nullable();
            $table->string('end_text')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_demo_videos', function (Blueprint $table) {
            $table->dropColumn('exports');
        });
        Schema::table('video_demo_styles', function (Blueprint $table) {
            $table->dropColumn(['logo', 'logo_position', 'end_title', 'end_text']);
        });
    }
};
