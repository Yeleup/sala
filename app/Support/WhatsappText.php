<?php

namespace App\Support;

/**
 * Truncates text for WhatsApp Cloud API interactive fields, which reject
 * a payload outright if any string exceeds its field limit (row title,
 * description, button title, body, ...). Truncation keeps the ellipsis
 * within the limit itself, unlike Illuminate\Support\Str::limit, which
 * appends its suffix on top of the given length.
 */
class WhatsappText
{
    private const string ELLIPSIS = '…';

    public static function clamp(?string $text, int $maxLength): ?string
    {
        if ($text === null) {
            return null;
        }

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        if ($maxLength <= 0) {
            return '';
        }

        return mb_substr($text, 0, $maxLength - 1).self::ELLIPSIS;
    }

    /**
     * Normalizes a template body parameter: Meta rejects parameters that
     * contain newlines, tabs or runs of consecutive spaces — and since
     * parameters now carry free text (the listing title, the customer's
     * query), any of those would fail the send. Whitespace runs collapse
     * to a single space.
     */
    public static function templateParameter(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }
}
