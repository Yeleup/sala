<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per real provider request — including retries and failovers.
 * Token usage comes from the SDK; the money is an estimate from the
 * pricing snapshot taken at call time (cost_status=unknown when the
 * model's tariff is not configured — never a silent zero).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_operation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->string('provider', 64)->nullable();
            $table->string('model', 128)->nullable();
            $table->string('invocation_id', 64)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('prompt')->nullable();
            $table->text('response')->nullable();
            $table->text('error')->nullable();
            $table->json('parameters')->nullable();
            $table->json('pricing_snapshot')->nullable();
            $table->decimal('estimated_cost_usd', 12, 6)->nullable();
            $table->string('cost_status', 16);
            $table->timestamps();

            $table->index(['model', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_attempts');
    }
};
