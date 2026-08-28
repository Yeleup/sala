<?php

use App\Enums\BotScenarioTrigger;
use App\Enums\ScenarioRunStatus;
use App\Models\BotScenario;
use App\Models\ChannelMessage;
use App\Models\Contact;
use App\Models\ScenarioRun;
use App\Models\WhatsappTemplate;
use App\Services\DereuMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.dereu.webhook_secret', 'whsec_test');
    config()->set('services.dereu.external_id', 'org_test');
    // Джобы вебхука должны исполниться в самом тесте (контейнерное окружение
    // перебивает phpunit.xml, поэтому очередь переключается здесь).
    config()->set('queue.default', 'sync');
});

/**
 * Сценарий с двумя вопросами подряд: нужен, чтобы отличить отказ по
 * сообщению, которого запуск ждёт прямо сейчас, от запоздалого отказа по
 * сообщению уже пройденного блока.
 */
function twoQuestionScenario(): BotScenario
{
    return BotScenario::factory()
        ->trigger(BotScenarioTrigger::ListingExpiring)
        ->published([
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'ask1', 'type' => 'message', 'text' => 'Первый вопрос', 'channel' => 'session',
                    'options' => [['id' => 'yes', 'title' => 'Да']]],
                ['id' => 'ask2', 'type' => 'message', 'text' => 'Второй вопрос', 'channel' => 'session',
                    'options' => [['id' => 'ok', 'title' => 'Хорошо']]],
                ['id' => 'end', 'type' => 'end'],
            ],
            'edges' => [
                ['from' => 'start', 'output' => 'continue', 'to' => 'ask1'],
                ['from' => 'ask1', 'output' => 'option:yes', 'to' => 'ask2'],
                ['from' => 'ask2', 'output' => 'option:ok', 'to' => 'end'],
            ],
        ])
        ->create(['name' => 'Два вопроса']);
}

function waitingRun(BotScenario $scenario, string $nodeId = 'ask1', ScenarioRunStatus $status = ScenarioRunStatus::Active): ScenarioRun
{
    return ScenarioRun::factory()->create([
        'bot_scenario_id' => $scenario->id,
        'scenario_version' => $scenario->published_version,
        'contact_id' => Contact::factory()->create()->id,
        'status' => $status,
        'current_node_id' => $nodeId,
        'timeout_at' => now()->addDay(),
    ]);
}

/** Строка журнала сообщения запуска с токен-кнопками указанных вариантов. */
function runMessageEntry(ScenarioRun $run, array $optionIds, string $uuid): ChannelMessage
{
    return ChannelMessage::factory()->outbound()->create([
        'contact_id' => $run->contact_id,
        'type' => 'interactive',
        'dereu_message_id' => $uuid,
        'payload' => [
            'type' => 'button',
            'body' => ['text' => 'Вопрос'],
            'action' => ['buttons' => array_map(
                fn (string $id): array => ['type' => 'reply', 'reply' => ['id' => "flow:{$run->token}:{$id}", 'title' => $id]],
                $optionIds,
            )],
        ],
    ]);
}

function failedRunDelivery(string $dereuMessageId, string $reason = 'meta error 131026: Message undeliverable.'): void
{
    $payload = [
        'event' => 'message_failed',
        'event_id' => (string) Str::ulid(),
        'company_id' => 'co_abc123',
        'phone_number_id' => '1234567890',
        'message_id' => $dereuMessageId,
        'reason' => $reason,
        'from' => null,
        'type' => null,
        'payload' => [],
        'timestamp' => 1718000000,
    ];

    test()->postJson(route('webhooks.dereu'), $payload, [
        'X-Dereu-Signature' => 'sha256='.hash_hmac('sha256', json_encode($payload), 'whsec_test'),
    ])->assertNoContent();
}

describe('недоставленный вопрос запуска', function () {
    test('асинхронный отказ Meta помечает ждущий запуск ошибкой отправки', function () {
        $run = waitingRun(twoQuestionScenario());
        $uuid = (string) Str::uuid();
        runMessageEntry($run, ['yes'], $uuid);

        failedRunDelivery($uuid);

        // «Ждёт ответа» по вопросу, который не дошёл ни до кого, — это
        // тишина, выданная за ожидание.
        expect($run->refresh()->status)->toBe(ScenarioRunStatus::Failed)
            ->and($run->timeout_at)->toBeNull();
    });

    test('запоздалый отказ по пройденному блоку запуск не роняет', function () {
        $scenario = twoQuestionScenario();
        $run = waitingRun($scenario, 'ask2');
        $uuid = (string) Str::uuid();
        runMessageEntry($run, ['yes'], $uuid);

        failedRunDelivery($uuid);

        expect($run->refresh()->status)->toBe(ScenarioRunStatus::Active)
            ->and($run->current_node_id)->toBe('ask2');
    });

    test('удачная переотправка шаблоном-фолбэком запуск не роняет — вопрос ещё в пути', function () {
        $run = waitingRun(twoQuestionScenario());
        $template = WhatsappTemplate::factory()->approved()->create();
        $uuid = (string) Str::uuid();
        runMessageEntry($run, ['yes'], $uuid)->update(['template_fallback' => [
            'whatsapp_template_id' => $template->id,
            'body_parameters' => ['Кран'],
            'button_payloads' => ["flow:{$run->token}:yes"],
        ]]);

        test()->mock(DereuMessenger::class)->shouldReceive('sendTemplate')->once();

        failedRunDelivery($uuid, 'Message failed to send because more than 24 hours have passed since the customer last replied to this number.');

        expect($run->refresh()->status)->toBe(ScenarioRunStatus::Active);
    });

    test('отказ по уже завершённому запуску его не переоткрывает', function () {
        $run = waitingRun(twoQuestionScenario(), 'ask1', ScenarioRunStatus::Completed);
        $uuid = (string) Str::uuid();
        runMessageEntry($run, ['yes'], $uuid);

        failedRunDelivery($uuid);

        expect($run->refresh()->status)->toBe(ScenarioRunStatus::Completed);
    });

    test('отказ по сообщению без токен-кнопок запуска не касается', function () {
        $run = waitingRun(twoQuestionScenario());
        $uuid = (string) Str::uuid();
        ChannelMessage::factory()->outbound()->create([
            'contact_id' => $run->contact_id,
            'dereu_message_id' => $uuid,
        ]);

        failedRunDelivery($uuid);

        expect($run->refresh()->status)->toBe(ScenarioRunStatus::Active);
    });
});
