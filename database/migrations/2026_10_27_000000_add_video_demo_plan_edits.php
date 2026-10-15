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
        // When the plan was last edited by hand, so a new plan can start from the edits.
        Schema::table('video_demo_videos', function (Blueprint $table) {
            $table->timestamp('plan_edited_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_demo_videos', function (Blueprint $table) {
            $table->dropColumn('plan_edited_at');
        });
    }
};
