<?php

namespace App\Services\Ai;

use App\Models\Listing;
use App\Models\Location;
use App\Services\Locations\LocationName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Hybrid matching of a customer query against searchable listings
 * (published and unexpired): exact word overlap keeps brand and model
 * queries («JCB 3CX») on top, semantic similarity of the query to the
 * listing's embedded text adds finds without a literal match («кран»
 * finds «автокран»). When the query vector is unavailable (provider
 * failure, non-pgsql driver) matching degrades to word overlap alone.
 *
 * When the query names a dictionary location, only that location's
 * subtree is considered: «кран в Шымкенте» covers the city and its
 * districts.
 */
class ListingMatcher
{
    /**
     * WhatsApp list messages hold at most 10 rows.
     */
    public const int MAX_RESULTS = 10;

    /**
     * Weights of the two ranking signals: word overlap dominates so exact
     * brand/model hits stay on top, similarity reorders and adds recall.
     */
    public const float KEYWORD_WEIGHT = 0.6;

    public const float VECTOR_WEIGHT = 0.4;

    /**
     * A listing with no matched words needs at least this cosine
     * similarity to enter the results — below it the search stays empty
     * and the assistant's clarification flow kicks in. A starting guess
     * for text-embedding-3-small: tune against logged real-traffic
     * similarities (see the debug log in rankHybrid()).
     */
    public const float MIN_SIMILARITY = 0.35;

    public function __construct(private readonly ListingEmbeddings $embeddings) {}

    /**
     * @return Collection<int, Listing>
     */
    public function match(string $query, ?Location $within = null): Collection
    {
        $tokens = $this->tokenize($query);

        if ($tokens === []) {
            return collect();
        }

        $vector = DB::getDriverName() === 'pgsql' ? $this->embeddings->queryVector($query) : null;

        $ranked = $vector === null
            ? $this->rankByKeywords($tokens, $within)
            : $this->rankHybrid($query, $tokens, $vector, $within);

        return $ranked
            ->sortBy([['score', 'desc'], ['listing.created_at', 'desc']])
            ->take(self::MAX_RESULTS)
            ->map(fn (array $item): Listing => $item['listing'])
            ->values();
    }

    /**
     * @param  list<string>  $tokens
     * @return Collection<int, array{listing: Listing, score: float}>
     */
    protected function rankByKeywords(array $tokens, ?Location $within): Collection
    {
        return $this->baseQuery($within)
            ->get()
            ->map(fn (Listing $listing): array => [
                'listing' => $listing,
                'score' => (float) $this->score($tokens, $listing),
            ])
            ->filter(fn (array $item): bool => $item['score'] > 0);
    }

    /**
     * Cosine similarity comes from pgvector; the combined score is a
     * weighted sum of the normalized word-overlap share and similarity.
     * A listing survives on matched words alone (no embedding row yet)
     * or on similarity above the threshold alone.
     *
     * @param  list<string>  $tokens
     * @param  array<float>  $vector
     * @return Collection<int, array{listing: Listing, score: float}>
     */
    protected function rankHybrid(string $query, array $tokens, array $vector, ?Location $within): Collection
    {
        $ranked = $this->baseQuery($within)
            ->leftJoin('listing_embeddings', 'listing_embeddings.listing_id', '=', 'listings.id')
            ->select('listings.*')
            ->selectRaw(
                '1 - (listing_embeddings.embedding <=> ?::vector) as vector_similarity',
                ['['.implode(',', $vector).']'],
            )
            ->get()
            ->map(function (Listing $listing) use ($tokens): array {
                $keyword = $this->score($tokens, $listing) / count($tokens);
                $similarity = $listing->vector_similarity === null ? 0.0 : (float) $listing->vector_similarity;

                return [
                    'listing' => $listing,
                    'keyword' => $keyword,
                    'similarity' => $similarity,
                    'score' => self::KEYWORD_WEIGHT * $keyword + self::VECTOR_WEIGHT * $similarity,
                ];
            });

        Log::debug('Hybrid listing search ranked.', [
            'query' => $query,
            'ranked' => $ranked
                ->map(fn (array $item): array => [
                    'listing_id' => $item['listing']->id,
                    'keyword' => round($item['keyword'], 3),
                    'similarity' => round($item['similarity'], 3),
                ])
                ->values()
                ->all(),
        ]);

        return $ranked->filter(
            fn (array $item): bool => $item['keyword'] > 0 || $item['similarity'] >= self::MIN_SIMILARITY,
        );
    }

    /**
     * @return Builder<Listing>
     */
    protected function baseQuery(?Location $within): Builder
    {
        return Listing::query()
            ->searchable()
            ->with(['supplier', 'category', 'brand', 'location'])
            ->when($within, fn (Builder $builder): Builder => $builder->whereHas(
                'location',
                fn (Builder $location) => $location->where('path', 'like', $within->path.'%'),
            ));
    }

    /**
     * @param  list<string>  $tokens
     */
    protected function score(array $tokens, Listing $listing): int
    {
        $haystack = Str::lower(implode(' ', array_filter([
            $listing->title,
            $listing->category?->name,
            $listing->brand?->name,
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
