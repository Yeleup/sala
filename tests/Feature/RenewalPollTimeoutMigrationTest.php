<?php

use App\Enums\BotScenarioTrigger;
use App\Enums\ScenarioRunStatus;
use App\Models\BotScenario;
use App\Models\ScenarioRun;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function runRenewalPollTimeoutMigration(): void
{
    (require database_path('migrations/2026_08_28_090000_give_renewal_polls_a_reply_timeout.php'))->up();
}

/**
 * Опрос продления в том виде, в каком он публиковался до появления срока
 * ожидания: блок с кнопками и без timeout_hours.
 */
function timeoutlessPollScenario(
    BotScenarioTrigger $trigger = BotScenarioTrigger::ListingExpiring,
    ?int $timeoutHours = null,
): BotScenario {
    $poll = ['id' => 'poll', 'type' => 'message', 'channel' => 'adaptive', 'template_name' => 'listing_renewal',
        'options' => [['id' => 'yes', 'title' => 'Да'], ['id' => 'no', 'title' => 'Нет']]];

    if ($timeoutHours !== null) {
        $poll['timeout_hours'] = $timeoutHours;
    }

    return BotScenario::factory()
        ->trigger($trigger)
        ->published([
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                $poll,
                ['id' => 'note', 'type' => 'message', 'channel' => 'session', 'text' => 'Готово.'],
                ['id' => 'end', 'type' => 'end'],
            ],
            'edges' => [
                ['from' => 'start', 'output' => 'continue', 'to' => 'poll'],
                ['from' => 'poll', 'output' => 'option:yes', 'to' => 'note'],
                ['from' => 'note', 'output' => 'continue', 'to' => 'end'],
            ],
        ])
        ->create();
}

function nodeOf(array|string|null $definition, string $nodeId): array
{
    $decoded = is_string($definition) ? json_decode($definition, true) : $definition;

    return collect($decoded['nodes'])->firstWhere('id', $nodeId);
}

function pollNodeOf(array|string|null $definition): array
{
    return nodeOf($definition, 'poll');
}

test('опубликованный опрос продления получает суточный срок ожидания', function () {
    $scenario = timeoutlessPollScenario();

    runRenewalPollTimeoutMigration();

    $scenario->refresh();

    // Снимок версии правится вместе с черновиком: запуск закреплён за
    // версией, и срок ожидания читается именно из неё.
    expect(pollNodeOf($scenario->published_definition)['timeout_hours'])->toBe(24)
        ->and(pollNodeOf($scenario->draft_definition)['timeout_hours'])->toBe(24)
        ->and(pollNodeOf($scenario->versions()->sole()->definition)['timeout_hours'])->toBe(24);
});

test('блок без кнопок срока ожидания не получает — он ничего не ждёт', function () {
    $scenario = timeoutlessPollScenario();

    runRenewalPollTimeoutMigration();

    expect(nodeOf($scenario->refresh()->published_definition, 'note'))->not->toHaveKey('timeout_hours');
});

test('срок ожидания, выставленный оператором вручную, не переписывается', function () {
    $scenario = timeoutlessPollScenario(timeoutHours: 6);

    runRenewalPollTimeoutMigration();

    expect(pollNodeOf($scenario->refresh()->published_definition)['timeout_hours'])->toBe(6);
});

test('сценарии не про продление миграция не трогает', function () {
    $scenario = timeoutlessPollScenario(BotScenarioTrigger::InboundMessage);

    runRenewalPollTimeoutMigration();

    expect(pollNodeOf($scenario->refresh()->published_definition))->not->toHaveKey('timeout_hours');
});

test('запуск, зависший в ожидании ответа, получает срок от начала ожидания', function () {
    $scenario = timeoutlessPollScenario();
    $run = ScenarioRun::factory()->create([
        'bot_scenario_id' => $scenario->id,
        'scenario_version' => $scenario->published_version,
        'current_node_id' => 'poll',
        'timeout_at' => null,
    ]);
    $waitingSince = $run->updated_at;

    runRenewalPollTimeoutMigration();

    // Иначе новый срок достался бы только будущим опросам, а те, из-за
    // которых журнал и состоит из одного «Ждёт ответа», висели бы вечно.
    expect($run->refresh()->timeout_at->equalTo($waitingSince->copy()->addDay()))->toBeTrue();
});

test('запуск с уже назначенным сроком не переписывается', function () {
    $scenario = timeoutlessPollScenario();
    $deadline = now()->addHours(3)->startOfSecond();
    $run = ScenarioRun::factory()->create([
        'bot_scenario_id' => $scenario->id,
        'scenario_version' => $scenario->published_version,
        'current_node_id' => 'poll',
        'timeout_at' => $deadline,
    ]);

    runRenewalPollTimeoutMigration();

    expect($run->refresh()->timeout_at->equalTo($deadline))->toBeTrue();
});

test('завершённый запуск срока ожидания не получает', function () {
    $scenario = timeoutlessPollScenario();
    $run = ScenarioRun::factory()->completed()->create([
        'bot_scenario_id' => $scenario->id,
        'scenario_version' => $scenario->published_version,
        'current_node_id' => null,
        'timeout_at' => null,
    ]);

    runRenewalPollTimeoutMigration();

    expect($run->refresh()->timeout_at)->toBeNull()
        ->and($run->status)->toBe(ScenarioRunStatus::Completed);
});
