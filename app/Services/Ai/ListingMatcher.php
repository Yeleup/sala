<?php

namespace App\Services\Ai;

use App\Models\Brand;
use App\Models\Category;
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
 * A misspelled search subject is corrected against the operator
 * dictionaries of categories and brands («эксковатор» finds экскаваторы)
 * — the same trigram tolerance place names get, deliberately limited to
 * the finite dictionaries: arbitrary listing words are never used as
 * correction targets.
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

    /**
     * The trigram similarity a dictionary word must reach to count as a
     * correction of a query token (mirrors the place-name tolerance).
     */
    private const float CORRECTION_SIMILARITY = 0.45;

    /**
     * Trigram similarity on very short tokens is noise, not correction.
     */
    private const int CORRECTION_MIN_TOKEN_LENGTH = 5;

    /**
     * The category/brand dictionary words, split and lowercased — cached
     * per instance (the matcher is resolved per request).
     *
     * @var list<string>|null
     */
    private ?array $dictionaryWords = null;

    public function __construct(private readonly ListingEmbeddings $embeddings) {}

    /**
     * The chat list: the top of the full ranking, capped to what a
     * WhatsApp list message can hold.
     *
     * @return Collection<int, Listing>
     */
    public function match(string $query, ?Location $within = null): Collection
    {
        return $this->matchAll($query, $within)->take(self::MAX_RESULTS)->values();
    }

    /**
     * The full ranking without the chat cap — the customer web catalog
     * shows every match, paginated on its side.
     *
     * @return Collection<int, Listing>
     */
    public function matchAll(string $query, ?Location $within = null): Collection
    {
        $tokens = $this->tokenize($query);

        if ($tokens === []) {
            return collect();
        }

        $corrections = $this->corrections($tokens);
        // The corrected wording feeds the embedding too: a misspelled
        // word makes a noisy query vector.
        $embeddingQuery = $this->applyCorrections($query, $corrections);

        $vector = DB::getDriverName() === 'pgsql' ? $this->embeddings->queryVector($embeddingQuery) : null;

        $ranked = $vector === null
            ? $this->rankByKeywords($tokens, $corrections, $within)
            : $this->rankHybrid($embeddingQuery, $tokens, $corrections, $vector, $within);

        return $ranked
            ->sortBy([['score', 'desc'], ['listing.created_at', 'desc']])
            ->map(fn (array $item): Listing => $item['listing'])
            ->values();
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, string>  $corrections
     * @return Collection<int, array{listing: Listing, score: float}>
     */
    protected function rankByKeywords(array $tokens, array $corrections, ?Location $within): Collection
    {
        return $this->baseQuery($within)
            ->get()
            ->map(fn (Listing $listing): array => [
                'listing' => $listing,
                'score' => (float) $this->score($tokens, $corrections, $listing),
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
     * @param  array<string, string>  $corrections
     * @param  array<float>  $vector
     * @return Collection<int, array{listing: Listing, score: float}>
     */
    protected function rankHybrid(string $query, array $tokens, array $corrections, array $vector, ?Location $within): Collection
    {
        $ranked = $this->baseQuery($within)
            ->leftJoin('listing_embeddings', 'listing_embeddings.listing_id', '=', 'listings.id')
            ->select('listings.*')
            ->selectRaw(
                '1 - (listing_embeddings.embedding <=> ?::vector) as vector_similarity',
                ['['.implode(',', $vector).']'],
            )
            ->get()
            ->map(function (Listing $listing) use ($tokens, $corrections): array {
                $keyword = $this->score($tokens, $corrections, $listing) / count($tokens);
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
     * @param  array<string, string>  $corrections
     */
    protected function score(array $tokens, array $corrections, Listing $listing): int
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
            function (string $token) use ($haystack, $corrections): bool {
                $variants = [$token, $this->stemmed($token)];

                if (isset($corrections[$token])) {
                    $variants[] = $corrections[$token];
                    $variants[] = $this->stemmed($corrections[$token]);
                }

                return array_any($variants, fn (string $variant): bool => str_contains($haystack, $variant));
            },
        ));
    }

    /**
     * Close-distortion corrections of the query tokens against the words
     * of the operator dictionaries («эксковатор» → «экскаваторы»). A
     * token the dictionaries already know (a substring either way) is
     * left alone; on non-pgsql drivers matching stays strictly exact.
     *
     * @param  list<string>  $tokens
     * @return array<string, string>
     */
    protected function corrections(array $tokens): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }

        $words = $this->dictionaryWords();

        if ($words === []) {
            return [];
        }

        $corrections = [];

        foreach ($tokens as $token) {
            if (mb_strlen($token) < self::CORRECTION_MIN_TOKEN_LENGTH || $this->isKnownWord($token, $words)) {
                continue;
            }

            // Both sides are stemmed: differing endings must not eat the
            // trigram similarity of the same misspelled word.
            $correction = $this->closestDictionaryWord($this->stemmed($token), $words);

            if ($correction !== null) {
                $corrections[$token] = $correction;
            }
        }

        return $corrections;
    }

    /**
     * @param  list<string>  $words
     */
    protected function isKnownWord(string $token, array $words): bool
    {
        $stem = $this->stemmed($token);

        return array_any(
            $words,
            fn (string $word): bool => str_contains($word, $token) || str_contains($token, $word)
                || str_contains($word, $stem) || str_contains($stem, $word),
        );
    }

    /**
     * @param  list<string>  $words
     */
    protected function closestDictionaryWord(string $token, array $words): ?string
    {
        $row = DB::selectOne(
            'select word from unnest(?::text[]) as word
             where word % ? and similarity(word, ?) >= ?
             order by similarity(word, ?) desc, word
             limit 1',
            [$this->pgTextArray($words), $token, $token, self::CORRECTION_SIMILARITY, $token],
        );

        return $row->word ?? null;
    }

    /**
     * The distinct stemmed words of the category and brand names,
     * lowercased — the safe correction vocabulary (arbitrary listing
     * words are not). Stems, because the word-overlap scoring matches
     * stems anyway and «эксковатор» is much closer to «экскаватор» than
     * to «экскаваторы».
     *
     * @return list<string>
     */
    protected function dictionaryWords(): array
    {
        return $this->dictionaryWords ??= Category::query()->pluck('name')
            ->merge(Brand::query()->pluck('name'))
            ->flatMap(fn (string $name): array => $this->tokenize($name))
            ->map(fn (string $word): string => $this->stemmed($word))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $corrections
     */
    protected function applyCorrections(string $query, array $corrections): string
    {
        foreach ($corrections as $token => $correction) {
            $query = (string) preg_replace('/'.preg_quote($token, '/').'/iu', $correction, $query);
        }

        return $query;
    }

    /**
     * @param  list<string>  $words
     */
    protected function pgTextArray(array $words): string
    {
        return '{'.implode(',', array_map(
            fn (string $word): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $word).'"',
            $words,
        )).'}';
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
