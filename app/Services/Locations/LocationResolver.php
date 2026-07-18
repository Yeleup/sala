<?php

namespace App\Services\Locations;

use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Maps free-form location wording («в Шымкенте», «село Аксуат») to KATO
 * dictionary nodes. The dictionary is the only source of truth: text that
 * resolves to nothing stays unresolved and the caller asks the user.
 *
 * A wording that matches nothing exactly but is a close distortion of a
 * dictionary name (a transcribed voice message: «Сарагаш» → «Сарыагаш»)
 * is corrected by trigram similarity — but only when the caller knows the
 * text names a place. Free-text queries stay strictly exact: fuzzy-matching
 * arbitrary words would bind places to lookalike nouns («трактор» is one
 * letter away from с.Трактовое).
 */
class LocationResolver
{
    /**
     * A WhatsApp list message holds at most 10 rows, so more candidates
     * than that are treated as «not resolved, ask more precisely».
     */
    public const int MAX_CANDIDATES = 10;

    /**
     * The trigram similarity a dictionary name must reach to count as a
     * close distortion of the given wording.
     */
    private const float FUZZY_SIMILARITY_THRESHOLD = 0.45;

    /**
     * Names within this distance of the best-scoring candidate compete
     * with it (resolved like same-named places); anything further is
     * noise next to a clearly better correction.
     */
    private const float FUZZY_SIMILARITY_WINDOW = 0.10;

    /**
     * Trigram similarity on very short keys is noise, not correction.
     */
    private const int FUZZY_MIN_KEY_LENGTH = 5;

    /**
     * All dictionary nodes matching the given wording — exactly, or (when
     * nothing matches exactly) by close-distortion correction: the fuzzy
     * candidates become the pick list, same as same-named places.
     *
     * @return Collection<int, Location>
     */
    public function resolve(string $text): Collection
    {
        $key = LocationName::searchKey($text);

        if ($key === '') {
            return new Collection;
        }

        $matches = $this->collapseAncestors(
            Location::query()->where('search_name', $key)->orderBy('depth')->orderBy('id')->get(),
        );

        return $matches->isNotEmpty() ? $matches : $this->fuzzyMatches($key);
    }

    /**
     * The single location the given place name refers to, tolerating close
     * distortions of the wording (a misheard voice message, a typo): exact
     * resolution first — the same rules as in a search query — then trigram
     * correction with the usual «biggest unique unit wins» arbitration.
     * Only for text known to name a place; free text goes to detectInQuery.
     */
    public function detectPlace(string $name): ?Location
    {
        $detected = $this->detectInQuery($name);

        if ($detected !== null) {
            return $detected;
        }

        $matches = $this->fuzzyMatches(LocationName::searchKey($name));

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->count() > 1) {
            return $this->uniqueShallowest($matches);
        }

        return null;
    }

    /**
     * The single location a free-text search query talks about, or null
     * when none or several are plausible (the caller falls back to plain
     * text matching). Longer name matches win over shorter ones.
     */
    public function detectInQuery(string $query): ?Location
    {
        $words = LocationName::searchWords($query);

        for ($size = min(3, count($words)); $size >= 1; $size--) {
            $keys = [];

            for ($offset = 0; $offset <= count($words) - $size; $offset++) {
                $key = implode(' ', array_slice($words, $offset, $size));

                if (mb_strlen($key) >= 3) {
                    $keys[] = $key;
                }
            }

            if ($keys === []) {
                continue;
            }

            $matches = $this->collapseAncestors(
                Location::query()->whereIn('search_name', array_unique($keys))->get(),
            );

            if ($matches->count() === 1) {
                return $matches->first();
            }

            if ($matches->count() > 1) {
                return $this->uniqueShallowest($matches);
            }
        }

        return null;
    }

    /**
     * Same-named places at different levels: «Астана» is the capital and a
     * village district somewhere else. In a search query the biggest unit
     * wins — but only when it is unambiguous at its level; same-named
     * villages stay unresolved.
     *
     * @param  Collection<int, Location>  $matches
     */
    protected function uniqueShallowest(Collection $matches): ?Location
    {
        $shallowest = $matches->sortBy('depth')->groupBy('depth')->first();

        return $shallowest !== null && $shallowest->count() === 1 ? $shallowest->first() : null;
    }

    /**
     * Autocomplete suggestions: nodes whose name starts with the typed
     * words — plus everything inside them, so «Шымкент» offers the city
     * and its districts. Several words narrow the branch: «Шымкент Абай…»
     * offers only nodes inside Шымкент. Parents come before children.
     *
     * @return Collection<int, Location>
     */
    public function suggest(string $search, int $limit = 10): Collection
    {
        $words = LocationName::searchWords($search);

        if ($words === []) {
            return new Collection;
        }

        $nodeKey = array_pop($words);
        $branchKey = implode(' ', $words);

        $anchors = Location::query()
            ->where('search_name', 'like', ($branchKey === '' ? $nodeKey : $branchKey).'%')
            ->orderBy('depth')
            ->limit(5)
            ->get();

        $query = Location::query()->orderBy('depth')->orderBy('name')->limit($limit);

        // «Шымкент Абайский»: the first word anchors the branch, the last
        // one filters the nodes inside it.
        if ($branchKey !== '' && $anchors->isNotEmpty()) {
            return $query
                ->where('search_name', 'like', $nodeKey.'%')
                ->where(function ($constraint) use ($anchors): void {
                    foreach ($anchors as $anchor) {
                        $constraint->orWhere('path', 'like', $anchor->path.'%');
                    }
                })
                ->get();
        }

        // One word: the matching nodes themselves and their subtrees.
        return $query
            ->where(function ($constraint) use ($nodeKey, $anchors): void {
                $constraint->where('search_name', 'like', $nodeKey.'%');

                foreach ($anchors as $anchor) {
                    $constraint->orWhere('path', 'like', $anchor->path.'%');
                }
            })
            ->get();
    }

    /**
     * Dictionary nodes whose names are close distortions of the given key.
     * Postgres-only: on other drivers matching stays strictly exact.
     *
     * @return Collection<int, Location>
     */
    protected function fuzzyMatches(string $key): Collection
    {
        $keys = $this->closeKeys($key);

        if ($keys === []) {
            return new Collection;
        }

        return $this->collapseAncestors(
            Location::query()->whereIn('search_name', $keys)->orderBy('depth')->orderBy('id')->get(),
        );
    }

    /**
     * Distinct dictionary search keys within the similarity window of the
     * best match for the given key — the plausible corrections of a
     * misheard or mistyped place name.
     *
     * @return list<string>
     */
    protected function closeKeys(string $key): array
    {
        if (mb_strlen($key) < self::FUZZY_MIN_KEY_LENGTH || DB::getDriverName() !== 'pgsql') {
            return [];
        }

        $candidates = Location::query()
            ->selectRaw('search_name, similarity(search_name, ?) as sim', [$key])
            ->whereRaw('similarity(search_name, ?) >= ?', [$key, self::FUZZY_SIMILARITY_THRESHOLD])
            ->groupBy('search_name')
            ->orderByDesc('sim')
            ->limit(self::MAX_CANDIDATES)
            ->get();

        $best = (float) ($candidates->first()->sim ?? 0);

        return $candidates
            ->filter(fn (Location $candidate): bool => (float) $candidate->sim >= $best - self::FUZZY_SIMILARITY_WINDOW)
            ->pluck('search_name')
            ->all();
    }

    /**
     * KATO wraps cities into same-named administrative nodes («Семей Г.А.»
     * → «г.Семей»); when both match, only the deepest (the actual place)
     * stays a candidate.
     *
     * @param  Collection<int, Location>  $candidates
     * @return Collection<int, Location>
     */
    protected function collapseAncestors(Collection $candidates): Collection
    {
        return $candidates
            ->reject(fn (Location $candidate): bool => $candidates->contains(
                fn (Location $other): bool => $other->isDescendantOf($candidate),
            ))
            ->values();
    }
}
