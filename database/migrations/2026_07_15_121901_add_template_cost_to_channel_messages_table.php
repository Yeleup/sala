<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost accounting for outbound Template Messages: the tariff snapshot from
 * config/whatsapp-pricing.php is fixed on the journal row at send time.
 * Session messages keep all four columns null. Rows journaled before cost
 * accounting existed keep cost_status = null and are excluded from money
 * sums (no retroactive backfill — today's tariffs are not yesterday's).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_messages', function (Blueprint $table) {
            $table->foreignId('whatsapp_template_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('estimated_cost_usd', 12, 6)->nullable();
            $table->string('cost_status', 16)->nullable();
            $table->json('pricing_snapshot')->nullable();

            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('channel_messages', function (Blueprint $table) {
            $table->dropIndex(['type', 'created_at']);
            $table->dropConstrainedForeignId('whatsapp_template_id');
            $table->dropColumn(['estimated_cost_usd', 'cost_status', 'pricing_snapshot']);
        });
    }
};
