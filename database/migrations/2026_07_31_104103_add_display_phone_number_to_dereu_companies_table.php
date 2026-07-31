<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The human-readable number Meta shows for the connected phone_number_id.
     *
     * Nullable: Dereu fetches it from Meta Graph softly — a failed fetch does
     * not fail the connect — and numbers connected before Dereu started
     * sending the field have no value at all.
     */
    public function up(): void
    {
        Schema::table('dereu_companies', function (Blueprint $table) {
            $table->string('display_phone_number')->nullable()->after('phone_number_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dereu_companies', function (Blueprint $table) {
            $table->dropColumn('display_phone_number');
        });
    }
};
