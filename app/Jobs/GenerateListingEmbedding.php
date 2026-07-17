<?php

namespace App\Jobs;

use App\Enums\AiOperationType;
use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\ListingEmbedding;
use App\Services\Ai\Audit\AiAudit;
use App\Services\Ai\ListingEmbeddings;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Laravel\Ai\Embeddings;

/**
 * (Re)generates the semantic search vector of a published listing.
 * Queued after commit so the transition into Published is already
 * visible; idempotent by the source hash, so duplicate dispatches and
 * retries skip the provider call.
 */
class GenerateListingEmbedding implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public Listing $listing) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('listing-embedding:'.$this->listing->id))
                ->releaseAfter(10)
                ->expireAfter(120),
        ];
    }

    public function handle(ListingEmbeddings $embeddings, AiAudit $audit): void
    {
        $listing = $this->listing->fresh(['category', 'brand', 'location', 'embedding']);

        if ($listing === null || $listing->status !== ListingStatus::Published) {
            return;
        }

        $stored = $listing->embedding;

        if ($stored !== null && $stored->source_hash === $embeddings->sourceHash($listing, $stored->model)) {
            return;
        }

        $response = $audit->run(
            AiOperationType::Embedding,
            fn () => Embeddings::for([$embeddings->sourceText($listing)])
                ->dimensions(ListingEmbeddings::DIMENSIONS)
                ->generate(),
            ['contact_id' => $listing->contact_id, 'listing_id' => $listing->id],
        );

        ListingEmbedding::updateOrCreate(
            ['listing_id' => $listing->id],
            [
                'embedding' => '['.implode(',', $response->first()).']',
                'source_hash' => $embeddings->sourceHash($listing, $response->meta->model),
                'model' => $response->meta->model,
            ],
        );
    }
}
