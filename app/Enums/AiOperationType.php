<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The business function an AI call served — the «за что платим» axis of
 * the usage report.
 */
enum AiOperationType: string implements HasLabel
{
    case ListingExtraction = 'listing_extraction';
    case Transcription = 'transcription';

    public function getLabel(): string
    {
        return match ($this) {
            self::ListingExtraction => 'Извлечение объявления',
            self::Transcription => 'Транскрибация аудио',
        };
    }
}
