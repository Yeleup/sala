<?php

namespace App\Models;

use App\Enums\ListingStatus;
use App\Support\RussianPlural;
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
     * The listing the poll names — «Ваше объявление «Автокран 25 т» и ещё
     * …». Meta reads a message that names a concrete object as an update
     * about an ongoing transaction (Utility); a message that only counts
     * things reads as a bulk notice (Marketing), which is four times the
     * price. So the batch question names one listing, exactly like the
     * per-listing question does.
     *
     * The most urgent one that has a name: a nameless listing is skipped
     * over rather than named «без названия». The order must be
     * deterministic — the session text inside the 24-hour window and the
     * template parameters outside it have to produce the same name — and
     * a bare `id` would be ambiguous across the pivot join.
     */
    public function namedListing(): ?Listing
    {
        $listings = $this->listings()
            ->with('category')
            ->orderBy('listings.expires_at')
            ->orderBy('listings.id')
            ->get();

        return $listings->first(fn (Listing $listing): bool => filled($listing->displayName()))
            ?? $listings->first();
    }

    /**
     * «и ещё 11 объявлений» — how many the poll does not name. A batch is
     * only ever built from more than one listing and its set is frozen at
     * send time, so the remainder is at least one; the single one reads
     * as a word, because «и ещё 1 объявление» stumbles where «и ещё одно
     * объявление» does not.
     */
    public static function restPhrase(int $rest): string
    {
        if ($rest === 1) {
            return 'одно объявление';
        }

        return $rest.' '.RussianPlural::choose($rest, 'объявление', 'объявления', 'объявлений');
    }
}
