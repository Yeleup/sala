<?php

namespace App\Services\Dictionaries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Near-duplicates already sitting in an operator dictionary.
 *
 * The dictionaries are not merely lists: the AI picks categories and
 * brands strictly out of them, and customer search corrects typos against
 * them. So «Автокран» and «Кран автомобильный», entered on two different
 * calls, split one kind of offer in two, and a customer asking for a crane
 * sees half the suppliers. Uniqueness of the name catches only an exact
 * repeat — this catches the rest.
 *
 * It only warns. «Автокран» and «Автовышка» are legitimately different,
 * and the operator on a call is the one who can tell.
 */
class SimilarNameLookup
{
    /**
     * How close two names must read to be worth showing. Trigram
     * similarity, so word order barely matters and a transposed or
     * swapped letter still matches («Эксковатор» → «Экскаватор»).
     *
     * The value is pg_trgm's own default. Stricter, and an abbreviation
     * slips through — «мкр Нурсат» and «микрорайон Нурсат» share only the
     * second word and land around 0.37. Looser, and genuinely different
     * offers start warning about each other: «Автокран» and «Автовышка»
     * sit at about 0.27, and they must stay separate.
     */
    private const float SIMILARITY = 0.3;

    private const int LIMIT = 5;

    /**
     * A shorter fragment resembles half the dictionary — the operator is
     * still typing, and a warning then is noise.
     */
    private const int MIN_LENGTH = 3;

    /**
     * The ready line for the field's helper text, or null when nothing
     * close enough is in the dictionary.
     *
     * @param  Builder<Model>  $dictionary
     */
    public function hint(Builder $dictionary, ?string $name): ?string
    {
        $similar = $this->similarTo($dictionary, $name);

        return $similar === []
            ? null
            : 'Уже есть похожие: '.implode(', ', $similar).'. Проверьте, не заводите ли дубль.';
    }

    /**
     * @param  Builder<Model>  $dictionary
     * @return list<string>
     */
    public function similarTo(Builder $dictionary, ?string $name): array
    {
        $name = trim((string) $name);

        if (mb_strlen($name) < self::MIN_LENGTH) {
            return [];
        }

        return $dictionary
            // Two ways to be a duplicate: the typed name is part of an
            // existing one («Кран» inside «Автокран»), or it simply reads
            // close to it («Эксковатор» next to «Экскаватор»).
            ->where(fn (Builder $query): Builder => $query
                ->whereRaw('name ilike ?', ['%'.self::escapeLike($name).'%'])
                ->orWhereRaw('similarity(lower(name), lower(?)) >= ?', [$name, self::SIMILARITY]))
            ->orderByRaw('similarity(lower(name), lower(?)) desc, name', [$name])
            ->limit(self::LIMIT)
            ->pluck('name')
            ->all();
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
