<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dereu_companies', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('name');
            $table->string('dereu_company_id')->unique();
            $table->text('api_key')->nullable();
            $table->string('waba_id')->nullable();
            $table->string('phone_number_id')->nullable()->unique();
            $table->string('display_phone_number')->nullable();
            $table->string('status')->default('provisioned');
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
