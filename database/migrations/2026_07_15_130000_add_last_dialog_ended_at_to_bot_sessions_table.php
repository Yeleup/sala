<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks when the contact last finished a dialog with the bot. The Start
 * block's optional «Повторное обращение» output uses it to skip the full
 * greeting for contacts who have talked to the bot before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_sessions', function (Blueprint $table) {
            $table->timestamp('last_dialog_ended_at')->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('bot_sessions', function (Blueprint $table) {
            $table->dropColumn('last_dialog_ended_at');
        });
    }
};
