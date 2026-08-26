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
    case LocationDisambiguation = 'location_disambiguation';
    case SearchQueryExtraction = 'search_query_extraction';
    case Transcription = 'transcription';
    case Embedding = 'embedding';
    case MenuRouting = 'menu_routing';

    public function getLabel(): string
    {
        return match ($this) {
            self::ListingExtraction => 'Извлечение объявления',
            self::LocationDisambiguation => 'Выбор места из одноимённых',
            self::SearchQueryExtraction => 'Разбор поискового запроса',
            self::Transcription => 'Транскрибация аудио',
            self::Embedding => 'Векторизация для поиска',
            self::MenuRouting => 'Маршрутизация текста из меню',
        };
    }
}
