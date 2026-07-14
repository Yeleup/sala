<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The free-text listing location becomes a KATO dictionary node plus an
     * optional free-text detail («центр», «мкр Нурсат»). No data transfer:
     * a pre-production decision, existing rows start with an empty location.
     */
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('location');
            $table->foreignId('location_id')
                ->nullable()
                ->after('description')
                ->index()
                ->constrained()
                ->restrictOnDelete();
            $table->string('location_detail')->nullable()->after('location_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
            $table->dropColumn('location_detail');
            $table->string('location')->nullable()->after('description');
        });
    }
};
