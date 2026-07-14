<?php

namespace App\Services\Ai;

use App\Models\Listing;
use App\Models\Location;
use App\Services\Locations\LocationName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Matching of a customer query against searchable listings (published and
 * unexpired) — the MVP algorithm from the spec: the more query words a
 * listing's text contains, the higher it ranks. No semantic search.
 *
 * When the query names a dictionary location, only that location's subtree
 * is considered: «кран в Шымкенте» covers the city and its districts.
 */
class ListingMatcher
{
    /**
     * WhatsApp list messages hold at most 10 rows.
     */
    public const int MAX_RESULTS = 10;

    /**
     * @return Collection<int, Listing>
     */
    public function match(string $query, ?Location $within = null): Collection
    {
        $tokens = $this->tokenize($query);

        if ($tokens === []) {
            return collect();
        }

        return Listing::query()
            ->searchable()
            ->with(['supplier', 'category', 'location'])
            ->when($within, fn (Builder $builder): Builder => $builder->whereHas(
                'location',
                fn (Builder $location) => $location->where('path', 'like', $within->path.'%'),
            ))
            ->get()
            ->map(fn (Listing $listing): array => [
                'listing' => $listing,
                'score' => $this->score($tokens, $listing),
            ])
            ->filter(fn (array $ranked): bool => $ranked['score'] > 0)
            ->sortBy([['score', 'desc'], ['listing.created_at', 'desc']])
            ->take(self::MAX_RESULTS)
            ->map(fn (array $ranked): Listing => $ranked['listing'])
            ->values();
    }

    /**
     * @param  list<string>  $tokens
     */
    protected function score(array $tokens, Listing $listing): int
    {
        $haystack = Str::lower(implode(' ', array_filter([
            $listing->category?->name,
            $listing->description,
            $listing->location?->name,
            $listing->location?->search_name,
            $listing->location_detail,
            $listing->price,
        ])));

        return count(array_filter(
            $tokens,
            fn (string $token): bool => str_contains($haystack, $token)
                || str_contains($haystack, $this->stemmed($token)),
        ));
    }

    /**
     * @return list<string>
     */
    protected function tokenize(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', Str::lower($query), $matches);

        return array_values(array_unique(array_filter(
            $matches[0],
            fn (string $token): bool => mb_strlen($token) >= 2,
        )));
    }

    /**
     * Case endings must not break the match («в Шымкенте» finds the
     * listing located in «г.Шымкент») — the location stemmer is light
     * enough to apply to any query word.
     */
    protected function stemmed(string $token): string
    {
        return LocationName::searchWords($token)[0] ?? $token;
    }
}
