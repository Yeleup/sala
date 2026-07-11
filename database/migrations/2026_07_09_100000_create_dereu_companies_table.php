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
     * Drops the legacy table from the first Dereu iteration: a row is now
     * created only after a completed Hosted Embedded Signup, so the Dereu
     * identifiers are always present. Cascade also removes foreign keys
     * left on prototype tables that no longer exist in the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop table if exists dereu_companies cascade');
        } else {
            Schema::dropIfExists('dereu_companies');
        }

        Schema::create('dereu_companies', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('name');
            $table->string('dereu_company_id')->unique();
            $table->string('waba_id');
            $table->string('phone_number_id')->unique();
            $table->text('api_key')->nullable();
            $table->string('status');
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dereu_companies');
    }
};
