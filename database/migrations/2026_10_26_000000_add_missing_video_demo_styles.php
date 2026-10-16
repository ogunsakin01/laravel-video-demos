<?php

use Illuminate\Database\Migrations\Migration;
use Ogunsakin01\VideoDemo\Models\VideoStyle;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Apps that published their config before Spotlight or Promo reel were
        // added had no entry for them, so the upgrades that add them added
        // nothing. They come from the package's own settings now.
        foreach (['spotlight', 'promo'] as $key) {
            VideoStyle::addBuiltIn($key);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The styles stay: videos may have been made with them since.
    }
};
