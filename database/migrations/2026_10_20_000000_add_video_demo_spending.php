<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Every AI cost, as it happens, kept even when its video is deleted.
        Schema::create('video_demo_spending', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('video_id')->nullable()->index();
            // What it was for: plan, improve, narrate, voice, complete or ideas.
            $table->string('kind', 20);
            $table->string('model')->nullable();
            $table->decimal('cost', 12, 6);
            $table->timestamp('created_at')->nullable();
        });

        // When the video's status last changed, for its progress bar.
        Schema::table('video_demo_videos', function (Blueprint $table) {
            $table->timestamp('status_at')->nullable();
        });

        // What was spent before, so the total stays the same.
        $now = now();
        foreach (DB::table('video_demo_videos')->where('cost', '>', 0)->get(['id', 'model', 'cost']) as $video) {
            DB::table('video_demo_spending')->insert(['video_id' => $video->id, 'kind' => 'plan', 'model' => $video->model, 'cost' => $video->cost, 'created_at' => $now]);
        }
        $ideas = (float) DB::table('video_demo_ideas')->sum('cost');
        if ($ideas > 0) {
            DB::table('video_demo_spending')->insert(['video_id' => null, 'kind' => 'ideas', 'model' => null, 'cost' => $ideas, 'created_at' => $now]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_demo_spending');
        Schema::table('video_demo_videos', function (Blueprint $table) {
            $table->dropColumn('status_at');
        });
    }
};
