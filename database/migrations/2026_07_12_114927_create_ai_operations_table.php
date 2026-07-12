<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A business-level AI operation (listing extraction, transcription) with
 * links to the actors involved. One operation groups the real provider
 * calls (ai_attempts) it took, including retries and failovers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_operations', function (Blueprint $table) {
            $table->id();
            $table->string('operation', 64);
            $table->string('status', 16);
            $table->text('error')->nullable();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bot_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('channel_message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['operation', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_operations');
    }
};
