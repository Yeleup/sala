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
        Schema::create('dereu_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event');
            $table->string('event_id');
            $table->string('dedupe_key')->unique();
            $table->string('company_id')->nullable()->index();
            $table->string('phone_number_id')->nullable();
            $table->string('wamid')->nullable()->index();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dereu_webhook_events');
    }
};
