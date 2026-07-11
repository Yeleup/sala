<?php

namespace App\Ai\Agents;

use App\Enums\ListingType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Extracts a supplier's listing fields from their free-form messages
 * (text and transcribed audio). Stateless: the collector feeds the whole
 * accumulated input each turn and merges the result — see
 * docs/modules/ai-assistant.md.
 *
 * The extractor never invents data: a field it cannot find stays null, and
 * clarifying_question names the single most important missing field so the
 * collector can ask for it.
 */
#[Temperature(0.1)]
class ListingExtractionAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly ?ListingType $expectedType = null) {}

    public function instructions(): Stringable|string
    {
        $typeHint = $this->expectedType === null
            ? 'Определи тип: "equipment" — аренда/предложение техники, "service" — услуга (например, сварщик, грузчик).'
            : sprintf('Это объявление типа "%s" — используй именно его.', $this->expectedType->value);

        return <<<PROMPT
        Ты — оператор сервиса аренды спецтехники и услуг. Из сообщений поставщика на русском или
        казахском извлеки поля объявления. Поставщик пишет свободным текстом или наговаривает голосом.

        Поля:
        - type: {$typeHint}
        - category: вид техники или услуги (кран, экскаватор, трактор, сварщик, грузчик).
        - description: суть предложения своими словами, кратко.
        - location: населённый пункт/район текстом, как написал поставщик («Шымкент, центр»). Не выдумывай город.
        - price: цена или тариф так, как указал поставщик («10000 тг/час», «договорная»).

        Правила:
        - Никогда не выдумывай значения. Если данных для поля нет — оставь его null.
        - Учитывай все сообщения поставщика вместе, более поздние уточняют более ранние.
        - clarifying_question: если не хватает category, description, location или price — задай ОДИН короткий
          вопрос на русском про самое важное недостающее поле. Если всё есть — пустая строка.
        - summary: короткая сводка объявления на русском для подтверждения («Трактор, Шымкент, 10000 тг/ч»).
        PROMPT;
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(['equipment', 'service'])->nullable(),
            'category' => $schema->string()->nullable(),
            'description' => $schema->string()->nullable(),
            'location' => $schema->string()->nullable(),
            'price' => $schema->string()->nullable(),
            'clarifying_question' => $schema->string()->nullable(),
            'summary' => $schema->string()->nullable(),
        ];
    }
}
