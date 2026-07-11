<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Drops the prototype table that exists in the dev database without a
     * migration. Cascade also removes foreign keys left on other prototype
     * tables that reference it.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop table if exists contacts cascade');
        } else {
            Schema::dropIfExists('contacts');
        }

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique();
            $table->string('profile_name')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
