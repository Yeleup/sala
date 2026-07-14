<?php

namespace App\Services\Locations;

use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;

/**
 * Maps free-form location wording («в Шымкенте», «село Аксуат») to KATO
 * dictionary nodes. The dictionary is the only source of truth: text that
 * resolves to nothing stays unresolved and the caller asks the user.
 */
class LocationResolver
{
    /**
     * A WhatsApp list message holds at most 10 rows, so more candidates
     * than that are treated as «not resolved, ask more precisely».
     */
    public const int MAX_CANDIDATES = 10;

    /**
     * All dictionary nodes matching the given wording.
     *
     * @return Collection<int, Location>
     */
    public function resolve(string $text): Collection
    {
        $key = LocationName::searchKey($text);

        if ($key === '') {
            return new Collection;
        }

        return $this->collapseAncestors(
            Location::query()->where('search_name', $key)->orderBy('depth')->orderBy('id')->get(),
        );
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
