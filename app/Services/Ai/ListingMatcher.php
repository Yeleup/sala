<?php

namespace App\Services\Ai;

use App\Models\Listing;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Exact text matching of a customer query against searchable listings
 * (published and unexpired) — the MVP algorithm from the spec: the more
 * query words a listing's text contains, the higher it ranks. No semantic
 * search; locations participate as plain text.
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
    public function match(string $query): Collection
    {
        $tokens = $this->tokenize($query);

        if ($tokens === []) {
            return collect();
        }

        return Listing::query()
            ->searchable()
            ->with('supplier')
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
            $listing->category,
            $listing->description,
            $listing->location,
            $listing->price,
        ])));

        return count(array_filter($tokens, fn (string $token): bool => str_contains($haystack, $token)));
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
}
