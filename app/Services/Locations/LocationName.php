<?php

namespace App\Services\Locations;

/**
 * Normalizes location names and user input to a common search key so
 * «г.Шымкент», «Шымкент» and «в Шымкенте» resolve to the same node.
 *
 * Steps: lowercase, fold Kazakh-specific letters to their Russian lookalikes
 * (so «Ақсуат» matches «Аксуат»), strip KATO type markers (г., с., район,
 * Г.А., с.о.), and stem case endings of every word («Астане» → «астан»,
 * «Абайском» → «абайск»).
 */
final class LocationName
{
    private const array LETTER_FOLD = [
        'ё' => 'е',
        'қ' => 'к',
        'ғ' => 'г',
        'ң' => 'н',
        'ө' => 'о',
        'ұ' => 'у',
        'ү' => 'у',
        'һ' => 'х',
        'ә' => 'а',
        'і' => 'и',
    ];

    public static function searchKey(string $name): string
    {
        return implode(' ', self::searchWords($name));
    }

    /**
     * @return list<string>
     */
    public static function searchWords(string $name): array
    {
        $value = mb_strtolower(trim($name));
        $value = strtr($value, self::LETTER_FOLD);

        // Composite markers first («г.» would eat the start of «г.а.»), and
        // only when nothing letter-like follows — «г.А» in «г.Астана» or
        // «с.О» in «с.Орда» are not markers.
        $value = (string) preg_replace('/\bг\.\s*а\.?(?!\p{L})/u', ' ', $value);
        $value = (string) preg_replace('/\bс\.\s*о\.?(?!\p{L})/u', ' ', $value);
        $value = (string) preg_replace('/\b(г|с|ст|п|пос)\./u', ' ', $value);
        $value = (string) preg_replace('/\b(область|обл|город|село|аул|поселок|посёлок)\b/u', ' ', $value);
        $value = (string) preg_replace('/\bрайон[а-я]*\b/u', ' ', $value);

        $value = (string) preg_replace('/[^\p{L}\p{N}\-]+/u', ' ', $value);

        $words = preg_split('/\s+/u', trim($value), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        // One-two letter leftovers are prepositions («в Шымкенте») or
        // stray initials, not toponym words.
        $words = array_filter(
            $words,
            fn (string $word): bool => mb_strlen($word) > 2 || preg_match('/\d/', $word) === 1,
        );

        return array_values(array_map(self::stem(...), $words));
    }

    /**
     * A deliberately light stemmer for toponyms: adjectival «-ский/-ском/
     * -ского…» collapse to «ск», otherwise one trailing vowel (or й/ь)
     * comes off — enough for Russian case endings without a morphology
     * library. Ambiguity this creates is resolved by the candidate list.
     */
    private static function stem(string $word): string
    {
        $stemmed = (string) preg_replace('/(ского|скому|скими|ского|ских|ским|ском|ский|ская|ское|ские|скую|скои|ской)$/u', 'ск', $word);

        if ($stemmed !== $word) {
            return $stemmed;
        }

        if (mb_strlen($word) >= 4) {
            $stemmed = (string) preg_replace('/[аеиоуыэюяйь]$/u', '', $word);
        }

        return $stemmed;
    }
}
