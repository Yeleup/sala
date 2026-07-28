<?php

namespace App\Support;

/**
 * Contact phone numbers in the form WhatsApp gives them: digits only, in
 * the international format without a «+». An operator taking a number
 * down from a phone call writes it the way it is dictated — «8 701 234 56
 * 78», «+7 701…», «7701-234-56-78» — and every spelling has to land on
 * the same contact: without logins the number is the only identity in the
 * product, so a duplicate contact means a listing on a dead number.
 */
class PhoneNumber
{
    /**
     * Kazakh numbers dictated with the domestic trunk prefix: «8 7XX …»
     * is the same subscriber as «+7 7XX …». Every KZ number starts with
     * 7 7, so the pair of leading digits is what makes the swap safe —
     * a foreign number that merely begins with 8 is left alone.
     */
    private const string DOMESTIC_PREFIX = '87';

    private const string COUNTRY_PREFIX = '77';

    private const int NUMBER_LENGTH = 11;

    /**
     * The canonical form to store and compare by. Returns null when the
     * text holds no digits at all, so an empty field stays empty rather
     * than becoming a bare «».
     */
    public static function normalize(?string $phone): ?string
    {
        $digits = self::digits($phone);

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === self::NUMBER_LENGTH && str_starts_with($digits, self::DOMESTIC_PREFIX)) {
            $digits = self::COUNTRY_PREFIX.substr($digits, strlen(self::DOMESTIC_PREFIX));
        }

        return $digits;
    }

    /**
     * Prefixes to look a contact up by while the operator is still
     * typing. Mid-typing there is no full length to decide by, so a
     * number started with «8» is searched both ways.
     *
     * @return list<string>
     */
    public static function searchPrefixes(?string $phone): array
    {
        $digits = self::digits($phone);

        if ($digits === '') {
            return [];
        }

        return str_starts_with($digits, '8')
            ? [$digits, '7'.substr($digits, 1)]
            : [$digits];
    }

    private static function digits(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }
}
