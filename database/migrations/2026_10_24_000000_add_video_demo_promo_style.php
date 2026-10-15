<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Apps that already copied the built-in styles get the Promo reel style too.
        if (DB::table('video_demo_styles')->exists() && ! DB::table('video_demo_styles')->where('key', 'promo')->exists()) {
            $style = config('video-demo.styles.promo');
            if (is_array($style)) {
                DB::table('video_demo_styles')->insert([
                    'key' => 'promo',
                    ...array_intersect_key($style, array_flip(['name', 'direction', 'theme', 'accent', 'captions', 'pace', 'art', 'app_theme', 'zoom', 'highlight', 'voice', 'music'])),
                    'position' => (int) DB::table('video_demo_styles')->max('position') + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('video_demo_styles')->where('key', 'promo')->delete();
    }
};
