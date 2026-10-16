<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Ogunsakin01\VideoDemo\Models\VideoStyle;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Apps that already copied the built-in styles get the Promo reel style too.
        VideoStyle::addBuiltIn('promo');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('video_demo_styles')->where('key', 'promo')->delete();
    }
};
