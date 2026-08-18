<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Снимок поставщика, которому ушло уведомление о заявке. Существующие
     * строки остаются NULL сознательно: неизвестно, кого уведомляли, —
     * такая заявка больше не блокирует повторную попытку.
     */
    public function up(): void
    {
        Schema::table('customer_requests', function (Blueprint $table) {
            $table->foreignId('supplier_contact_id')
                ->nullable()
                ->constrained('contacts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_contact_id');
        });
    }
};
