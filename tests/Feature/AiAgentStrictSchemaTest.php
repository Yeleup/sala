<?php

use App\Ai\Agents\ListingExtractionAgent;
use App\Ai\Agents\LocationChoiceAgent;
use App\Ai\Agents\MenuRouteAgent;
use App\Ai\Agents\SearchQueryExtractionAgent;
use App\Enums\ListingKind;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\ObjectSchema;

test('схема агента уходит в строгом режиме и целиком покрыта required', function (object $agent) {
    // Без #[Strict] enum категорий/марок/мест — пожелание, а не ограничение:
    // «Автокраны» вместо словарного «Автокран» тихо сбрасывается в null, и
    // бот сжигает попытку уточнения на вопрос, на который уже ответили.
    expect(Strict::isAppliedTo($agent))->toBeTrue();

    // OpenAI в строгом режиме требует каждый ключ properties в required —
    // иначе запрос отвергается ещё до генерации.
    $schema = (new ObjectSchema($agent->schema(new JsonSchemaTypeFactory), strict: true))->toSchema();

    expect($schema['required'] ?? [])->toEqualCanonicalizing(array_keys($schema['properties']));
})->with([
    'извлечение объявления (аренда)' => fn (): object => new ListingExtractionAgent(ListingKind::Rental, ['Автокран'], ['Hitachi']),
    'извлечение объявления (ремонт)' => fn (): object => new ListingExtractionAgent(ListingKind::Repair),
    'извлечение объявления (водитель)' => fn (): object => new ListingExtractionAgent(ListingKind::Driver, ['Экскаватор']),
    'разбор поискового запроса' => fn (): object => new SearchQueryExtractionAgent,
    'разбор поискового запроса (ремонт)' => fn (): object => new SearchQueryExtractionAgent(ListingKind::Repair),
    'разбор поискового запроса (водитель)' => fn (): object => new SearchQueryExtractionAgent(ListingKind::Driver),
    'выбор одноимённого места' => fn (): object => new LocationChoiceAgent([7 => 'Абайский район, Карагандинская область', 9 => 'Абайский район, Шымкент']),
    'маршрутизация текста из меню' => fn (): object => new MenuRouteAgent(['option:supplier' => '«Кто вы?» → «Поставщик»'], 'вернуться к прерванной анкете (Аренда спецтехники)'),
    'маршрутизация текста из меню (продолжать нечего)' => fn (): object => new MenuRouteAgent(['option:supplier' => '«Кто вы?» → «Поставщик»']),
]);
