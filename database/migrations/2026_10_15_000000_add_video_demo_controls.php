<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_demo_videos', function (Blueprint $table) {
            // The most this video may spend on AI, and the most steps its plan may have
            $table->decimal('max_cost', 10, 4)->nullable();
            $table->unsignedInteger('max_steps')->nullable();
            // Who the recorder signs in as: a user id or email, or null for a guest
            $table->string('record_as')->nullable();
            $table->string('record_as_label')->nullable();
            // Pages found broken before or during recording
            $table->json('issues')->nullable();
            // Set by the Stop button; the job stops at its next safe point
            $table->boolean('stop_requested')->default(false);
        });

        // Ideas the AI suggested, kept until replaced or deleted
        Schema::create('video_demo_ideas', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('prompt');
            $table->text('why')->nullable();
            $table->string('start_path')->nullable();
            $table->unsignedInteger('target_seconds')->default(60);
            $table->string('style')->nullable();
            $table->string('model')->nullable();
            $table->decimal('cost', 12, 6)->default(0);
            $table->foreignId('video_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_demo_ideas');

        Schema::table('video_demo_videos', function (Blueprint $table) {
            $table->dropColumn(['max_cost', 'max_steps', 'record_as', 'record_as_label', 'issues', 'stop_requested']);
        });
    }
};
