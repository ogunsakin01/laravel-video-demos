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
        // Daily counts of how the published videos are watched, when the app
        // turns it on: plays, quarters reached, and searches that found nothing.
        Schema::create('video_demo_stats', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 191)->default('');
            $table->date('day');
            $table->string('event', 16);
            $table->string('term', 191)->default('');
            $table->unsignedInteger('hits')->default(0);
            $table->unique(['slug', 'day', 'event', 'term']);
            $table->index(['day', 'event']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_demo_stats');
    }
};
