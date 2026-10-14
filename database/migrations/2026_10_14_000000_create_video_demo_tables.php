<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Videos being made in the studio. Only used on developers' machines;
        // production reads the published manifest instead.
        Schema::create('video_demo_videos', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title')->nullable();
            $table->text('prompt');
            $table->string('start_path')->nullable();
            $table->unsignedInteger('target_seconds');
            $table->string('model');
            $table->string('effort');
            $table->string('visibility')->default('public');
            // A copy of the style used, so recording again looks the same
            $table->json('style')->nullable();
            // Versions to record (desktop, tv, tablet, mobile) and, per version,
            // what the last recording produced
            $table->json('devices')->nullable();
            $table->json('recordings')->nullable();
            $table->string('status');
            $table->json('plan')->nullable();
            $table->json('log')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost', 12, 6)->default(0);
            $table->decimal('estimate_low', 10, 4)->nullable();
            $table->decimal('estimate_high', 10, 4)->nullable();
            $table->decimal('duration_seconds', 8, 2)->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        // The styles offered when making a video, first copied from config
        Schema::create('video_demo_styles', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('direction');
            $table->string('theme')->default('light');
            $table->string('accent')->default('#171717');
            $table->string('captions')->default('bottom');
            $table->string('pace')->default('normal');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        // Videos your app's users asked for, stored where they asked (production)
        Schema::create('video_demo_requests', function (Blueprint $table) {
            $table->id();
            $table->text('question');
            $table->string('page_url')->nullable();
            $table->string('user_id')->nullable();
            $table->string('status')->default('open');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_demo_requests');
        Schema::dropIfExists('video_demo_styles');
        Schema::dropIfExists('video_demo_videos');
    }
};
