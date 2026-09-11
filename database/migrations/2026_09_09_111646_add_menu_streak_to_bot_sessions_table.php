<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A run of messages the bot failed to understand on one and the same menu
 * step: how many of them there have been, and what was written.
 *
 * The count is what stops the ping-pong — free text, menu, free text,
 * menu, over and over; one contact was shown «Что вас интересует?» 29
 * times. What was written is what ends it better than silence: a person
 * listing what they repair one word per message («Мотор», «Воздушные»,
 * «Ходовка») means nothing word by word and is unmistakable read together.
 *
 * Its own column rather than a key inside `state`, which is single-owner:
 * the navigator's proposal, the questionnaire and the search each
 * overwrite it wholesale, so a counter kept there would vanish at moments
 * nobody could reproduce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_sessions', function (Blueprint $table): void {
            $table->json('menu_streak')->nullable()->after('paused_state');
        });
    }

    public function down(): void
    {
        Schema::table('bot_sessions', function (Blueprint $table): void {
            $table->dropColumn('menu_streak');
        });
    }
};
