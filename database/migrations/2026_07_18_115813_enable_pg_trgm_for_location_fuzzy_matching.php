<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Trigram similarity for fuzzy matching of misheard or mistyped
     * place names against the location dictionary. On non-pgsql drivers
     * the similarity SQL never runs: matching stays strictly exact.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        }
    }

    public function down(): void
    {
        // The extension is shared infrastructure: dropping it could break
        // other consumers, so rolling back leaves it in place.
    }
};
