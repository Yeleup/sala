<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the 30-day renewal poll was sent to the supplier — so the daily
 * cycle asks once per publication period, not every day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->timestamp('renewal_requested_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('renewal_requested_at');
        });
    }
};
