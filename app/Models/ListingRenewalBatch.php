<?php

namespace App\Models;

use App\Enums\ListingStatus;
use Database\Factories\ListingRenewalBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One 30-day relevance poll that covers several listings of the same
 * supplier at once. A supplier whose publications expire on the same day
 * gets a single question instead of one paid template per listing, and
 * answers for the whole set with one button.
 *
 * The set is captured at send time: an answer that arrives days later
 * applies to exactly the listings the supplier was asked about, never to
 * a publication that appeared in the meantime.
 */
#[Fillable(['contact_id'])]
class ListingRenewalBatch extends Model
{
    /** @use HasFactory<ListingRenewalBatchFactory> */
    use HasFactory;

    /** @return BelongsTo<Contact, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * The pivot is named after the batch, not after Laravel's alphabetical
     * default: «listing_listing_renewal_batch» reads as a typo.
     *
     * @return BelongsToMany<Listing, $this>
     */
    public function listings(): BelongsToMany
    {
        return $this->belongsToMany(Listing::class, 'listing_renewal_batch_listing');
    }

    /**
     * The listings of this batch that are still waiting for an answer to
     * this very poll. Three things drop a listing out, and each is a
     * decision that already happened without the button:
     *
     * - it is no longer published (archived by the supplier, the operator
     *   or the auto-archive of an expired publication);
     * - it belongs to another supplier now (the operator reassigned it) —
     *   the buttons die with the ownership, as everywhere else in the
     *   project;
     * - the poll mark is gone, i.e. the publication was renewed or
     *   returned from the archive since. A button of a superseded cycle
     *   must not reach into the new 30-day period.
     *
     * @return Collection<int, Listing>
     */
    public function pendingListings(): Collection
    {
        return $this->pending()->get();
    }

    public function hasPendingListings(): bool
    {
        return $this->pending()->exists();
    }

    /** @return BelongsToMany<Listing, $this> */
    protected function pending(): BelongsToMany
    {
        return $this->listings()
            ->where('listings.status', ListingStatus::Published)
            ->where('listings.contact_id', $this->contact_id)
            ->whereNotNull('listings.renewal_requested_at');
    }

    /** «Все актуальны»: every publication of the batch lives another 30 days. */
    public function renewAll(): void
    {
        $this->pendingListings()->each(fn (Listing $listing) => $listing->renew());
    }

    /** «Все в архив»: the whole batch leaves the search at once. */
    public function archiveAll(): void
    {
        $this->pendingListings()->each(fn (Listing $listing) => $listing->archive());
    }

    /**
     * «12 ваших объявлений» — the poll's only template parameter. The
     * genitive is what the wording «У {{1}} скоро закончится срок показа»
     * needs, and it stays grammatical for any count: two forms are enough
     * («21 вашего объявления», «22 ваших объявлений»).
     */
    public static function countPhrase(int $count): string
    {
        $singular = $count % 10 === 1 && $count % 100 !== 11;

        return $count.($singular ? ' вашего объявления' : ' ваших объявлений');
    }
}
