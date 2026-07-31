<?php

use App\Ai\Agents\ListingExtractionAgent;
use App\Ai\Agents\LocationChoiceAgent;
use App\Ai\Agents\SearchQueryExtractionAgent;
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
    'извлечение объявления' => fn (): object => new ListingExtractionAgent(null, ['Автокран'], ['Hitachi']),
    'разбор поискового запроса' => fn (): object => new SearchQueryExtractionAgent,
    'выбор одноимённого места' => fn (): object => new LocationChoiceAgent([7 => 'Абайский район, Карагандинская область', 9 => 'Абайский район, Шымкент']),
]);
