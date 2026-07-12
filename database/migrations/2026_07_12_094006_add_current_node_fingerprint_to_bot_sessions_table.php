<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fingerprint of the block the contact is waiting at (type, options, AI
 * task) — after a republication the engine compares it to the new schema
 * and soft-resets the dialog when the block became incompatible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_sessions', function (Blueprint $table) {
            $table->string('current_node_fingerprint', 64)->nullable()->after('current_node_id');
        });
    }

    public function down(): void
    {
        Schema::table('bot_sessions', function (Blueprint $table) {
            $table->dropColumn('current_node_fingerprint');
        });
    }
};
