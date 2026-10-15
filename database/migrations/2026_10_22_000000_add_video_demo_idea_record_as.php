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
        // The type of user an idea's video is meant for, such as type:admin or guest.
        Schema::table('video_demo_ideas', function (Blueprint $table) {
            $table->string('record_as')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_demo_ideas', function (Blueprint $table) {
            $table->dropColumn('record_as');
        });
    }
};
