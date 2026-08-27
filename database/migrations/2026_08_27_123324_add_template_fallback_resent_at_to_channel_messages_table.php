<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the paid template of a plan B actually went out. The claim that
 * guards the plan B erases template_fallback, so «never had one» and
 * «already spent it, successfully» look identical afterwards — and they
 * are opposite answers to «is this notification still on its way?». A
 * re-run of the same failure event (job retry, or the five-minute sweep
 * of unprocessed events) read the erased column as «never had one» and
 * un-marked a renewal poll whose replacement question was already
 * delivered, so the supplier got asked twice and paid for twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_messages', function (Blueprint $table): void {
            $table->timestamp('template_fallback_resent_at')->nullable()->after('template_fallback');
        });
    }

    public function down(): void
    {
        Schema::table('channel_messages', function (Blueprint $table): void {
            $table->dropColumn('template_fallback_resent_at');
        });
    }
};
