<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A trigram GIN index for the fuzzy place-name correction: similarity()
 * alone cannot use an index, so the lookups pair it with the % operator
 * (see LocationResolver::closeKeys()), which this index accelerates.
 * Postgres-only, like the pg_trgm extension itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS locations_search_name_trgm_index ON locations USING gin (search_name gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS locations_search_name_trgm_index');
        }
    }
};
