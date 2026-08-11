<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The service is equipment-only for now: the listing type asked nothing the
 * category did not already say, and the category dictionary becomes a flat
 * list. No data is lost — not a single row was ever of the service type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // The original column had no default; one is required here to
            // give the existing rows a value on the way back.
            $table->string('type')->default('equipment')->after('created_by_user_id');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->string('type')->default('equipment')->after('name');
        });
    }
};
