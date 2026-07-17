<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Understands what a customer is searching for from their free-form
 * messages (text and transcribed audio). Stateless: the search assistant
 * feeds the whole accumulated input each turn and merges the result —
 * see docs/modules/ai-assistant.md.
 *
 * The extractor never invents data: a piece it cannot find stays null,
 * and clarifying_question names the single most important missing piece
 * (the need first, then the place) so the assistant can ask for it
 * before showing listings.
 */
#[Temperature(0.1)]
class SearchQueryExtractionAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        Ты — оператор сервиса аренды спецтехники и услуг. Из сообщений заказчика на русском или
        казахском пойми, что он ищет. Заказчик пишет свободным текстом или наговаривает голосом.
        Учитывай все сообщения вместе, более поздние уточняют и заменяют более ранние.

        Поля:
        - subject: что нужно заказчику — техника или услуга, словами самого заказчика, без вежливых
          оборотов и без названия места. Марки, модели и характеристики сохраняй дословно
          («кран 25 тонн», «экскаватор JCB 3CX»). Если из сообщений непонятно, что нужно, — null.
        - location: где нужна техника или услуга — ТОЛЬКО название места в именительном падеже, без
          слов «в», «город», «село»: «Шымкент», «Абайский район». Не выдумывай место; не названо — null.
        - location_any: true, только если заказчик явно сказал, что место не важно (подойдёт любой
          город, по всей стране). Иначе false.
        - clarifying_question: если subject отсутствует или место не названо (и не «любое») — задай
          ОДИН короткий вопрос на русском про самое важное недостающее: сначала про предмет поиска,
          потом про место. Если всё есть — пустая строка.

        Правила:
        - Никогда не выдумывай значения. Если данных нет — оставь поле null.
        PROMPT;
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'subject' => $schema->string()->nullable(),
            'location' => $schema->string()->nullable(),
            'location_any' => $schema->boolean(),
            'clarifying_question' => $schema->string()->nullable(),
        ];
    }
}
