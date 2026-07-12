<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local registry of the project's WhatsApp Template Messages. Templates
 * live in Meta (via Dereu); this table mirrors their moderation status so
 * outbound code can pick an approved template without an API round-trip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('language', 16);
            $table->string('category');
            $table->string('status')->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->text('body')->nullable();
            $table->json('components')->nullable();
            $table->unsignedBigInteger('dereu_template_id')->nullable();
            $table->timestamps();

            $table->unique(['name', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
