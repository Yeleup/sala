<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What starts the scenario: the contact's own inbound message (the
     * main dialog), a system event (new customer request, listing about
     * to expire) or a manual operator broadcast. Existing scenarios are
     * the main dialog by definition.
     */
    public function up(): void
    {
        Schema::table('bot_scenarios', function (Blueprint $table) {
            $table->string('trigger')->default('inbound_message');
        });
    }

    public function down(): void
    {
        Schema::table('bot_scenarios', function (Blueprint $table) {
            $table->dropColumn('trigger');
        });
    }
};
