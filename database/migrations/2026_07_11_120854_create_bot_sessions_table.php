<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drops the prototype table that exists in the dev database without a
     * migration.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop table if exists bot_sessions cascade');
        } else {
            Schema::dropIfExists('bot_sessions');
        }

        Schema::create('bot_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('bot_scenario_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('scenario_version');
            $table->string('current_node_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_sessions');
    }
};
