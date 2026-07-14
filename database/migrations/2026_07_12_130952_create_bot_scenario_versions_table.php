<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable snapshots of every publication. A scenario run started
     * days ago keeps following the exact graph that sent its message,
     * even after the operator republishes the scenario — otherwise an old
     * template button would land in a reshaped branch.
     */
    public function up(): void
    {
        Schema::create('bot_scenario_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_scenario_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('definition');
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['bot_scenario_id', 'version']);
        });

        // Backfill the current publication of already-published scenarios
        // so existing sessions and future runs can resolve it.
        DB::table('bot_scenarios')
            ->where('published_version', '>', 0)
            ->whereNotNull('published_definition')
            ->orderBy('id')
            ->each(function (object $scenario): void {
                DB::table('bot_scenario_versions')->insert([
                    'bot_scenario_id' => $scenario->id,
                    'version' => $scenario->published_version,
                    'definition' => $scenario->published_definition,
                    'published_at' => $scenario->published_at ?? now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_scenario_versions');
    }
};
