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
}
