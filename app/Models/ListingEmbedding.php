<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The semantic search vector of a published listing. The embedding
 * travels as a pgvector literal string («[0.1,0.2,…]»); source_hash
 * fingerprints the model + source text so unchanged listings are not
 * re-embedded.
 */
#[Fillable(['listing_id', 'embedding', 'source_hash', 'model'])]
class ListingEmbedding extends Model
{
    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
