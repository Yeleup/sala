<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The full journal of WhatsApp traffic: every inbound and outbound
 * message with its raw payload, direction, delivery status and timing.
 * Outbound rows are correlated with Dereu delivery webhooks through
 * dereu_message_id (the uuid returned by POST /messages/send).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 16);
            $table->string('type', 32);
            $table->text('text')->nullable();
            $table->json('payload');
            $table->string('wamid')->nullable();
            $table->string('dereu_message_id', 64)->nullable()->unique();
            $table->string('status', 16);
            $table->text('failure_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index('wamid');
            $table->index(['contact_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_messages');
    }
};
