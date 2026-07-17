<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Semantic search vectors for published listings, one row per listing.
     * The dimension count is a snapshot of ListingEmbeddings::DIMENSIONS
     * at the time this migration was written. On non-pgsql drivers the
     * vector is stored as opaque text: similarity SQL never runs there.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }

        Schema::create('listing_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->unique()->constrained()->cascadeOnDelete();

            if (DB::getDriverName() === 'pgsql') {
                $table->vector('embedding', dimensions: 1536);
            } else {
                $table->text('embedding');
            }

            $table->string('source_hash', 64);
            $table->string('model');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_embeddings');
    }
};
