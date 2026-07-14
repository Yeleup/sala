<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The name the supplier set themselves (web portal or operator).
     * Unlike profile_name it is never overwritten by the WhatsApp
     * webhook, so a manually chosen name survives inbound messages.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('profile_name');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('display_name');
        });
    }
};
