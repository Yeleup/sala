<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The paid-template plan B of a session notification: what to re-send
     * when Meta rejects the message asynchronously as outside the 24-hour
     * window. Template sends never carry one, so a re-send cannot loop.
     */
    public function up(): void
    {
        Schema::table('channel_messages', function (Blueprint $table) {
            $table->json('template_fallback')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channel_messages', function (Blueprint $table) {
            $table->dropColumn('template_fallback');
        });
    }
};
