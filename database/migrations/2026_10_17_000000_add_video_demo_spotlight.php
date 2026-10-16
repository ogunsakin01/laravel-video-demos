<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ogunsakin01\VideoDemo\Models\VideoStyle;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('video_demo_styles', function (Blueprint $table) {
            // The colour scheme the app is recorded in: auto, light or dark.
            $table->string('app_theme')->default('auto');
            // Zoom in on each step, and outline what is being used.
            $table->boolean('zoom')->default(false);
            $table->boolean('highlight')->default(false);
        });

        // Apps that already copied the built-in styles get the new one too.
        VideoStyle::addBuiltIn('spotlight');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('video_demo_styles')->where('key', 'spotlight')->delete();

        Schema::table('video_demo_styles', function (Blueprint $table) {
            $table->dropColumn(['app_theme', 'zoom', 'highlight']);
        });
    }
};
