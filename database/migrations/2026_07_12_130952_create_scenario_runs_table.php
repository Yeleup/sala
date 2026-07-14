<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One run per proactive send: a supplier may simultaneously await
     * answers about several requests and listings, so runs live next to —
     * never instead of — the contact's main dialog session. The token is
     * the opaque key carried by every button of the run's messages
     * (flow:{token}:{option}), routing a reply to the exact run, version
     * and block that sent it.
     */
    public function up(): void
    {
        Schema::create('scenario_runs', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('bot_scenario_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('scenario_version');
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('subject');
            $table->string('status');
            $table->string('current_node_id')->nullable();
            $table->timestamp('timeout_at')->nullable();
            $table->timestamps();

            $table->index(['contact_id', 'status']);
            $table->index(['status', 'timeout_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenario_runs');
    }
};
