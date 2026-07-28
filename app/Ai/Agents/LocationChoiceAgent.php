<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Picks the place a supplier meant out of the same-named dictionary nodes
 * the resolver could not tell apart («Абайский район» exists in two
 * oblasts). The dictionary itself is never shown to the model — only the
 * handful of candidates already found — and the answer is constrained to
 * their ids by the response schema, so a place outside the list cannot
 * come back.
 *
 * The model answers only when the supplier's own messages settle it;
 * otherwise it returns null and the collector asks with a pick list. A
 * guess would silently publish the listing in a foreign region, which is
 * worse than one more question — see docs/modules/ai-assistant.md.
 */
#[Temperature(0.1)]
class LocationChoiceAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  array<int, string>  $candidates  Dictionary node ids mapped to their full «место, район, область» chains.
     */
    public function __construct(private readonly array $candidates = []) {}

    public function instructions(): Stringable|string
    {
        $list = implode("\n", array_map(
            fn (int $id, string $label): string => sprintf('- %d: %s', $id, $label),
            array_keys($this->candidates),
            array_values($this->candidates),
        ));

        return <<<PROMPT
        Ты — оператор сервиса аренды спецтехники и услуг. Поставщик назвал место, но в справочнике
        несколько мест с таким названием. Определи по сообщениям поставщика, какое из них он имеет
        в виду.

        Варианты (id: место, район, область):
        {$list}

        Правила:
        - Верни location_id варианта, только если сообщения поставщика прямо на него указывают:
          назван район, город или область, внутри которых лежит вариант; или тип места (город, село,
          район) совпадает ровно с одним вариантом; или вариант единственный, который вообще может
          иметься в виду по смыслу переписки.
        - Не выбирай по размеру, известности или порядку в списке.
        - Сомневаешься — верни null. Лишний вопрос поставщику лучше, чем молча привязанный чужой
          регион.
        - Ответ — только id из списка выше или null. Ничего не выдумывай.
        PROMPT;
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'location_id' => $this->candidates === []
                ? $schema->string()->nullable()
                : $schema->string()->enum(array_map(strval(...), array_keys($this->candidates)))->nullable(),
        ];
    }
}
