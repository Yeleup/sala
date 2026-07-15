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
 * collector can ask for it. The category and the brand are constrained to
 * the operator's dictionaries both in the prompt and in the response
 * schema, so the model physically cannot return a value outside the lists.
 * Unlike the category, the brand is optional and never asked about.
 */
#[Temperature(0.1)]
class ListingExtractionAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  list<string>  $categories  Dictionary of allowed category names.
     * @param  list<string>  $brands  Dictionary of allowed equipment brand names.
     */
    public function __construct(
        private readonly ?ListingType $expectedType = null,
        private readonly array $categories = [],
        private readonly array $brands = [],
    ) {}

    public function instructions(): Stringable|string
    {
        $typeHint = $this->expectedType === null
            ? 'Определи тип: "equipment" — аренда/предложение техники, "service" — услуга (например, сварщик, грузчик). Тип должен соответствовать выбранной категории. Если тип неясен даже из фото — оставь type равным null, не угадывай.'
            : sprintf('Это объявление типа "%s" — используй именно его.', $this->expectedType->value);

        $categoryHint = $this->categories === []
            ? 'справочник категорий пуст — всегда оставляй category равным null.'
            : 'выбери одну категорию СТРОГО из списка ниже — дословно, как в списке. Не придумывай и не перефразируй категории; если ни одна не подходит или ты не уверен — оставь null.';

        $categoryList = $this->categories === []
            ? ''
            : "\n\nДоступные категории (только из этого списка):\n".implode("\n", array_map(
                fn (string $category): string => '- '.$category,
                $this->categories,
            ));

        $brandHint = $this->brands === []
            ? 'справочник марок пуст — всегда оставляй brand равным null.'
            : 'марка (производитель) техники, если поставщик назвал её текстом или голосом, — СТРОГО из списка ниже, дословно. Не выдумывай марку и не угадывай её по фото или модели; если марка не названа, её нет в списке или это услуга — оставь null. Марка необязательна: никогда не задавай уточняющий вопрос про неё.';

        $brandList = $this->brands === []
            ? ''
            : "\n\nДоступные марки (только из этого списка):\n".implode("\n", array_map(
                fn (string $brand): string => '- '.$brand,
                $this->brands,
            ));

        return <<<PROMPT
        Ты — оператор сервиса аренды спецтехники и услуг. Из сообщений поставщика на русском или
        казахском извлеки поля объявления. Поставщик пишет свободным текстом, наговаривает голосом
        или присылает фотографии — фото тоже источник данных: по ним определяй тип и категорию
        техники и дополняй описание, но локацию и цену по фото не выдумывай.

        Поля:
        - type: {$typeHint}
        - category: {$categoryHint}
        - brand: {$brandHint}
        - description: суть предложения своими словами, кратко.
        - location: где находится техника или оказывается услуга — ТОЛЬКО название места в именительном
          падеже, без слов «в», «город», «село»: «Шымкент», «Аксуат», «Ауэзовский район». Самое точное из
          названного: район города, город или село. Не выдумывай место.
        - location_detail: уточнение внутри места, если поставщик его назвал («центр», «мкр Нурсат»,
          «вдоль трассы»). Нет уточнения — null.
        - price: цена или тариф так, как указал поставщик («10000 тг/час», «договорная»).

        Правила:
        - Никогда не выдумывай значения. Если данных для поля нет — оставь его null.
        - Учитывай все сообщения поставщика вместе, более поздние уточняют более ранние.
        - clarifying_question: если не хватает category, description, location или price — задай ОДИН короткий
          вопрос на русском про самое важное недостающее поле. Если всё есть — пустая строка.
        - summary: короткая сводка объявления на русском для подтверждения, с маркой, если она есть
          («Трактор Hitachi, Шымкент, 10000 тг/ч»).{$categoryList}{$brandList}
        PROMPT;
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(['equipment', 'service'])->nullable(),
            'category' => $this->categories === []
                ? $schema->string()->nullable()
                : $schema->string()->enum($this->categories)->nullable(),
            'brand' => $this->brands === []
                ? $schema->string()->nullable()
                : $schema->string()->enum($this->brands)->nullable(),
            'description' => $schema->string()->nullable(),
            'location' => $schema->string()->nullable(),
            'location_detail' => $schema->string()->nullable(),
            'price' => $schema->string()->nullable(),
            'clarifying_question' => $schema->string()->nullable(),
            'summary' => $schema->string()->nullable(),
        ];
    }
}
