<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The fleet update broadcast was cut from the specification: only the
     * automatic 30-day renewal cycle stays. Remove broadcast scenarios
     * (their versions and runs cascade) and the fleet_status_update
     * template rows so the retired manual_broadcast trigger value never
     * reaches the enum cast.
     */
    public function up(): void
    {
        DB::table('bot_scenarios')->where('trigger', 'manual_broadcast')->delete();
        DB::table('whatsapp_templates')->where('name', 'fleet_status_update')->delete();
    }

    public function down(): void
    {
        // Deleted broadcast data is not restorable.
    }
};
