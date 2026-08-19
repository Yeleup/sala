<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One 30-day relevance poll covering several listings of the same
     * supplier: the set is captured at send time, so the answer that
     * arrives days later applies to exactly the listings the supplier was
     * asked about — never to something published in the meantime.
     */
    public function up(): void
    {
        Schema::create('listing_renewal_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('listing_renewal_batch_listing', function (Blueprint $table) {
            $table->foreignId('listing_renewal_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();

            $table->primary(['listing_renewal_batch_id', 'listing_id'], 'listing_renewal_batch_listing_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_renewal_batch_listing');
        Schema::dropIfExists('listing_renewal_batches');
    }
};
