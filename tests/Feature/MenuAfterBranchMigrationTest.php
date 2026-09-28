<?php

use App\Enums\AiOutcome;
use App\Enums\BotScenarioTrigger;
use App\Models\BotScenario;
use App\Models\BotSession;
use App\Models\Contact;
use App\Services\Bot\AiAssistant;
use App\Services\Bot\BotEngine;
use App\Services\Bot\InboundMessage;
use App\Services\Bot\MenuRouter;
use App\Services\Bot\NullMenuRouter;
use App\Services\Bot\ScenarioDefinition;
use App\Services\DereuMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(fn () => app()->bind(MenuRouter::class, NullMenuRouter::class));

function runMenuAfterBranchMigration(): void
{
    (require database_path('migrations/2026_09_28_085214_stop_sending_main_menu_after_finished_branches.php'))->up();
}

/**
 * Главный диалог в том виде, в каком его публиковали до изменения: выход
 * «Продолжить» всех шести AI-блоков и блока «Мои объявления» ведёт в
 * главное меню.
 *
 * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
 */
function legacyMainDialog(): array
{
    return [
        'nodes' => [
            ['id' => 'start', 'type' => 'start', 'x' => 40, 'y' => 400],
            ['id' => 'greeting', 'type' => 'text', 'x' => 260, 'y' => 400, 'text' => 'Здравствуйте! Это сервис спецтехники.'],
            ['id' => 'main_menu', 'type' => 'buttons', 'x' => 500, 'y' => 400, 'text' => 'Что вас интересует?', 'options' => [
                ['id' => 'kind_rental', 'title' => 'Аренда спецтехники'],
                ['id' => 'kind_repair', 'title' => 'Ремонт спецтехники'],
                ['id' => 'kind_driver', 'title' => 'Водитель / машинист'],
            ]],
            ['id' => 'menu_rental', 'type' => 'buttons', 'x' => 740, 'y' => 140, 'text' => 'Аренда. Сдаёте или ищете?', 'options' => [
                ['id' => 'rent_out', 'title' => 'Я сдаю спецтехнику'],
                ['id' => 'rent_seek', 'title' => 'Я ищу спецтехнику'],
                ['id' => 'my', 'title' => 'Мои объявления'],
            ]],
            ['id' => 'menu_repair', 'type' => 'buttons', 'x' => 740, 'y' => 400, 'text' => 'Ремонт. Мастер или ищете?', 'options' => [
                ['id' => 'master', 'title' => 'Я мастер'],
                ['id' => 'master_seek', 'title' => 'Я ищу мастера'],
                ['id' => 'my_repair', 'title' => 'Мои объявления'],
            ]],
            ['id' => 'menu_driver', 'type' => 'buttons', 'x' => 740, 'y' => 660, 'text' => 'Водители. Водитель или ищете?', 'options' => [
                ['id' => 'driver', 'title' => 'Я водитель'],
                ['id' => 'driver_seek', 'title' => 'Я ищу водителя'],
                ['id' => 'my_driver', 'title' => 'Мои объявления'],
            ]],
            ['id' => 'collect_rental', 'type' => 'ai', 'task' => 'collect_listing', 'kind' => 'rental', 'x' => 1000, 'y' => 40],
            ['id' => 'search_rental', 'type' => 'ai', 'task' => 'customer_search', 'kind' => 'rental', 'x' => 1000, 'y' => 160],
            ['id' => 'collect_repair', 'type' => 'ai', 'task' => 'collect_listing', 'kind' => 'repair', 'x' => 1000, 'y' => 300],
            ['id' => 'search_repair', 'type' => 'ai', 'task' => 'customer_search', 'kind' => 'repair', 'x' => 1000, 'y' => 420],
            ['id' => 'collect_driver', 'type' => 'ai', 'task' => 'collect_listing', 'kind' => 'driver', 'x' => 1000, 'y' => 560],
            ['id' => 'search_driver', 'type' => 'ai', 'task' => 'customer_search', 'kind' => 'driver', 'x' => 1000, 'y' => 680],
            ['id' => 'my_listings', 'type' => 'my_listings', 'x' => 1000, 'y' => 820, 'text' => 'Ваши объявления собраны в кабинете.'],
        ],
        'edges' => [
            ['from' => 'start', 'output' => 'continue', 'to' => 'greeting'],
            ['from' => 'start', 'output' => 'returning', 'to' => 'main_menu'],
            ['from' => 'greeting', 'output' => 'continue', 'to' => 'main_menu'],
            ['from' => 'main_menu', 'output' => 'option:kind_rental', 'to' => 'menu_rental'],
            ['from' => 'main_menu', 'output' => 'option:kind_repair', 'to' => 'menu_repair'],
            ['from' => 'main_menu', 'output' => 'option:kind_driver', 'to' => 'menu_driver'],
            ['from' => 'menu_rental', 'output' => 'option:rent_out', 'to' => 'collect_rental'],
            ['from' => 'menu_rental', 'output' => 'option:rent_seek', 'to' => 'search_rental'],
            ['from' => 'menu_rental', 'output' => 'option:my', 'to' => 'my_listings'],
            ['from' => 'menu_repair', 'output' => 'option:master', 'to' => 'collect_repair'],
            ['from' => 'menu_repair', 'output' => 'option:master_seek', 'to' => 'search_repair'],
            ['from' => 'menu_repair', 'output' => 'option:my_repair', 'to' => 'my_listings'],
            ['from' => 'menu_driver', 'output' => 'option:driver', 'to' => 'collect_driver'],
            ['from' => 'menu_driver', 'output' => 'option:driver_seek', 'to' => 'search_driver'],
            ['from' => 'menu_driver', 'output' => 'option:my_driver', 'to' => 'my_listings'],
            ['from' => 'collect_rental', 'output' => 'continue', 'to' => 'main_menu'],
            ['from' => 'collect_repair', 'output' => 'continue', 'to' => 'main_menu'],
            ['from' => 'collect_driver', 'output' => 'continue', 'to' => 'main_menu'],
            ['from' => 'search_rental', 'output' => 'continue', 'to' => 'main_menu'],
            ['from' => 'search_repair', 'output' => 'continue', 'to' => 'main_menu'],
            ['from' => 'search_driver', 'output' => 'continue', 'to' => 'main_menu'],
            ['from' => 'my_listings', 'output' => 'continue', 'to' => 'main_menu'],
        ],
    ];
}

/**
 * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}|null  $definition
 */
function publishedMainDialog(?array $definition = null): BotScenario
{
    return BotScenario::factory()->published($definition ?? legacyMainDialog())->create();
}

/**
 * @param  array<string, mixed>  $definition
 * @return list<string>
 */
function edgesOf(array $definition): array
{
    return array_map(
        fn (array $edge): string => "{$edge['from']} --{$edge['output']}--> {$edge['to']}",
        $definition['edges'],
    );
}

const LEGACY_AI_NODES = ['collect_rental', 'collect_repair', 'collect_driver', 'search_rental', 'search_repair', 'search_driver'];

test('типовые связи «Продолжить → главное меню» переезжают на выход «В меню», у «Моих объявлений» снимаются', function () {
    $scenario = publishedMainDialog();

    runMenuAfterBranchMigration();

    $scenario->refresh();

    foreach (['published_definition', 'draft_definition'] as $column) {
        $definition = new ScenarioDefinition($scenario->{$column});

        foreach (LEGACY_AI_NODES as $nodeId) {
            expect($definition->target($nodeId, ScenarioDefinition::OUTPUT_CONTINUE))->toBeNull()
                ->and($definition->target($nodeId, ScenarioDefinition::OUTPUT_MENU))->toBe('main_menu');
        }

        expect($definition->target('my_listings', ScenarioDefinition::OUTPUT_CONTINUE))->toBeNull()
            ->and($definition->target('my_listings', ScenarioDefinition::OUTPUT_MENU))->toBeNull();
    }
});

test('всё, кроме типовых связей, остаётся как было: блоки, тексты, кнопки, расположение и прочие связи', function () {
    $legacy = legacyMainDialog();
    $scenario = publishedMainDialog($legacy);

    runMenuAfterBranchMigration();

    $migrated = $scenario->refresh()->published_definition;

    expect($migrated['nodes'])->toBe($legacy['nodes'])
        ->and(edgesOf($migrated))->toBe([
            'start --continue--> greeting',
            'start --returning--> main_menu',
            'greeting --continue--> main_menu',
            'main_menu --option:kind_rental--> menu_rental',
            'main_menu --option:kind_repair--> menu_repair',
            'main_menu --option:kind_driver--> menu_driver',
            'menu_rental --option:rent_out--> collect_rental',
            'menu_rental --option:rent_seek--> search_rental',
            'menu_rental --option:my--> my_listings',
            'menu_repair --option:master--> collect_repair',
            'menu_repair --option:master_seek--> search_repair',
            'menu_repair --option:my_repair--> my_listings',
            'menu_driver --option:driver--> collect_driver',
            'menu_driver --option:driver_seek--> search_driver',
            'menu_driver --option:my_driver--> my_listings',
            'collect_rental --menu--> main_menu',
            'collect_repair --menu--> main_menu',
            'collect_driver --menu--> main_menu',
            'search_rental --menu--> main_menu',
            'search_repair --menu--> main_menu',
            'search_driver --menu--> main_menu',
        ])
        // Связи остаются списком, а не словарём с дырами в номерах.
        ->and(array_is_list($migrated['edges']))->toBeTrue();
});

test('правки оператора не затираются: его тексты, его блоки и «Продолжить», который он направил сам', function () {
    $custom = legacyMainDialog();
    $custom['nodes'][2]['text'] = 'Чем можем помочь?';
    $custom['nodes'][2]['x'] = 512.5;
    $custom['nodes'][] = ['id' => 'n_thanks', 'type' => 'text', 'x' => 1300, 'y' => 40, 'text' => 'Спасибо за объявление!'];
    $custom['edges'] = array_map(fn (array $edge): array => match ([$edge['from'], $edge['output']]) {
        // Оператор сам повёл завершение анкеты аренды в свой текст…
        ['collect_rental', 'continue'] => [...$edge, 'to' => 'n_thanks'],
        // …а завершение поиска аренды — на экран раздела, не в главное меню.
        ['search_rental', 'continue'] => [...$edge, 'to' => 'menu_rental'],
        default => $edge,
    }, $custom['edges']);
    $custom['edges'][] = ['from' => 'n_thanks', 'output' => 'continue', 'to' => 'main_menu'];
    $scenario = publishedMainDialog($custom);

    runMenuAfterBranchMigration();

    $migrated = $scenario->refresh()->published_definition;
    $definition = new ScenarioDefinition($migrated);

    expect($migrated['nodes'])->toBe($custom['nodes'])
        ->and($definition->target('collect_rental', ScenarioDefinition::OUTPUT_CONTINUE))->toBe('n_thanks')
        ->and($definition->target('collect_rental', ScenarioDefinition::OUTPUT_MENU))->toBeNull()
        ->and($definition->target('search_rental', ScenarioDefinition::OUTPUT_CONTINUE))->toBe('menu_rental')
        ->and($definition->target('search_rental', ScenarioDefinition::OUTPUT_MENU))->toBeNull()
        // Текстовый блок оператора ведёт в меню по-прежнему: это его связь,
        // а не завершение AI-блока или «Моих объявлений».
        ->and($definition->target('n_thanks', ScenarioDefinition::OUTPUT_CONTINUE))->toBe('main_menu')
        // Остальные ветки оператор не трогал — они получают новое поведение.
        ->and($definition->target('collect_repair', ScenarioDefinition::OUTPUT_CONTINUE))->toBeNull()
        ->and($definition->target('collect_repair', ScenarioDefinition::OUTPUT_MENU))->toBe('main_menu')
        ->and($definition->target('my_listings', ScenarioDefinition::OUTPUT_CONTINUE))->toBeNull();
});

test('черновик и опубликованная схема правятся каждый по своему графу', function () {
    $scenario = publishedMainDialog();
    $draft = legacyMainDialog();
    $draft['nodes'][] = ['id' => 'n_draft_only', 'type' => 'text', 'x' => 1300, 'y' => 160, 'text' => 'Ещё не опубликовано'];
    $draft['edges'] = array_map(
        fn (array $edge): array => [$edge['from'], $edge['output']] === ['search_driver', 'continue'] ? [...$edge, 'to' => 'n_draft_only'] : $edge,
        $draft['edges'],
    );
    $scenario->update(['draft_definition' => $draft]);

    runMenuAfterBranchMigration();

    $scenario->refresh();
    $draftDefinition = new ScenarioDefinition($scenario->draft_definition);
    $publishedDefinition = new ScenarioDefinition($scenario->published_definition);

    expect($draftDefinition->target('search_driver', ScenarioDefinition::OUTPUT_CONTINUE))->toBe('n_draft_only')
        ->and($draftDefinition->target('collect_driver', ScenarioDefinition::OUTPUT_MENU))->toBe('main_menu')
        ->and($draftDefinition->node('n_draft_only'))->not->toBeNull()
        ->and($publishedDefinition->target('search_driver', ScenarioDefinition::OUTPUT_CONTINUE))->toBeNull()
        ->and($publishedDefinition->target('search_driver', ScenarioDefinition::OUTPUT_MENU))->toBe('main_menu')
        ->and($publishedDefinition->node('n_draft_only'))->toBeNull()
        // Несохранённая разница между черновиком и публикацией осталась.
        ->and($scenario->hasUnpublishedChanges())->toBeTrue();
});

test('выход «В меню», уже подключённый оператором, не переписывается', function () {
    $custom = legacyMainDialog();
    $custom['edges'][] = ['from' => 'collect_rental', 'output' => 'menu', 'to' => 'menu_rental'];
    $scenario = publishedMainDialog($custom);

    runMenuAfterBranchMigration();

    $migrated = $scenario->refresh()->published_definition;
    $definition = new ScenarioDefinition($migrated);

    expect($definition->target('collect_rental', ScenarioDefinition::OUTPUT_MENU))->toBe('menu_rental')
        ->and($definition->target('collect_rental', ScenarioDefinition::OUTPUT_CONTINUE))->toBeNull()
        ->and(collect($migrated['edges'])->where('from', 'collect_rental')->where('output', 'menu'))->toHaveCount(1);
});

test('блок, стоящий на самом пути от «Старта» к меню, не считается веткой, вернувшейся в меню', function () {
    $entry = [
        'nodes' => [
            ['id' => 'start', 'type' => 'start'],
            ['id' => 'cabinet', 'type' => 'my_listings', 'text' => 'Сначала загляните в кабинет.'],
            ['id' => 'menu', 'type' => 'buttons', 'text' => 'Что дальше?', 'options' => [['id' => 'again', 'title' => 'Ещё раз']]],
        ],
        'edges' => [
            ['from' => 'start', 'output' => 'continue', 'to' => 'cabinet'],
            ['from' => 'cabinet', 'output' => 'continue', 'to' => 'menu'],
            ['from' => 'menu', 'output' => 'option:again', 'to' => 'cabinet'],
        ],
    ];
    $scenario = publishedMainDialog($entry);

    runMenuAfterBranchMigration();

    expect($scenario->refresh()->published_definition)->toBe($entry);
});

test('повторный прогон ничего не меняет', function () {
    $scenario = publishedMainDialog();

    runMenuAfterBranchMigration();
    $once = DB::table('bot_scenarios')->where('id', $scenario->id)->first(['draft_definition', 'published_definition']);

    runMenuAfterBranchMigration();
    $twice = DB::table('bot_scenarios')->where('id', $scenario->id)->first(['draft_definition', 'published_definition']);

    expect($twice->published_definition)->toBe($once->published_definition)
        ->and($twice->draft_definition)->toBe($once->draft_definition);
});

test('сценарий без типовых связей остаётся байт в байт прежним', function () {
    $this->artisan('bot:install-default-scenario', ['--only' => BotScenarioTrigger::InboundMessage->value])->assertSuccessful();
    $before = DB::table('bot_scenarios')->first(['draft_definition', 'published_definition']);

    runMenuAfterBranchMigration();

    $after = DB::table('bot_scenarios')->first(['draft_definition', 'published_definition']);

    expect($after->published_definition)->toBe($before->published_definition)
        ->and($after->draft_definition)->toBe($before->draft_definition);
});

test('на пустой базе и на ни разу не опубликованном сценарии миграция проходит', function () {
    runMenuAfterBranchMigration();

    expect(BotScenario::count())->toBe(0);

    $draftOnly = BotScenario::factory()->create(['draft_definition' => legacyMainDialog()]);

    runMenuAfterBranchMigration();

    $draftOnly->refresh();

    expect($draftOnly->published_definition)->toBeNull()
        ->and($draftOnly->isPublished())->toBeFalse()
        ->and((new ScenarioDefinition($draftOnly->draft_definition))->target('collect_rental', ScenarioDefinition::OUTPUT_MENU))->toBe('main_menu');
});

test('сценарии уведомлений не трогаются — «Мои объявления» пачечного продления ведёт в «Завершение»', function () {
    $this->artisan('bot:install-default-scenario')->assertSuccessful();
    $before = BotScenario::query()->where('trigger', '!=', BotScenarioTrigger::InboundMessage)->get()
        ->mapWithKeys(fn (BotScenario $scenario): array => [$scenario->id => [$scenario->draft_definition, $scenario->published_definition]]);

    runMenuAfterBranchMigration();

    $after = BotScenario::query()->where('trigger', '!=', BotScenarioTrigger::InboundMessage)->get()
        ->mapWithKeys(fn (BotScenario $scenario): array => [$scenario->id => [$scenario->draft_definition, $scenario->published_definition]]);
    $batch = BotScenario::publishedForTrigger(BotScenarioTrigger::ListingsExpiringBatch);

    expect($before)->toHaveCount(3)
        ->and($after->all())->toBe($before->all())
        ->and($batch->publishedDefinition()->target('cabinet', ScenarioDefinition::OUTPUT_CONTINUE))->toBe('end');
});

test('номер версии и снимки версий остаются прежними', function () {
    $scenario = publishedMainDialog();
    $version = $scenario->published_version;
    $snapshot = $scenario->versions()->sole()->definition;

    runMenuAfterBranchMigration();

    expect($scenario->refresh()->published_version)->toBe($version)
        ->and($scenario->versions()->sole()->definition)->toBe($snapshot);
});

describe('живой диалог после миграции', function () {
    /**
     * Контакт, дошедший до шага ещё по прежней схеме.
     *
     * @param  array<string, mixed>|null  $state
     */
    function legacySessionAt(BotScenario $scenario, string $nodeId, ?array $state = null): BotSession
    {
        $definition = $scenario->publishedDefinition();

        return BotSession::factory()->waitingAt($nodeId)->create([
            'bot_scenario_id' => $scenario->id,
            'scenario_version' => $scenario->published_version,
            'current_node_fingerprint' => $definition->nodeFingerprint($definition->node($nodeId)),
            'last_dialog_ended_at' => now()->subMinutes(5),
            'state' => $state,
        ]);
    }

    test('ждущий в анкете продолжает её с того же шага', function () {
        $scenario = publishedMainDialog();
        $state = ['kind' => 'repair', 'phase' => 'collecting', 'transcript' => ['чиню гидравлику']];
        $session = legacySessionAt($scenario, 'collect_repair', $state);

        runMenuAfterBranchMigration();

        $assistant = test()->mock(AiAssistant::class);
        $assistant->shouldNotReceive('start');
        $assistant->shouldReceive('resume')->once()
            ->withArgs(fn (BotSession $s, array $node, InboundMessage $message): bool => $node['id'] === 'collect_repair'
                && $s->state === $state
                && $message->text === 'работаю в Алматы')
            ->andReturn(AiOutcome::InProgress);
        test()->mock(DereuMessenger::class)->shouldNotReceive('sendText', 'sendButtons', 'sendList', 'sendCtaUrl');

        app(BotEngine::class)->handle($session->contact, new InboundMessage(text: 'работаю в Алматы'));

        expect($session->fresh())
            ->current_node_id->toBe('collect_repair')
            ->state->toBe($state);
    });

    test('ждущий в меню идёт по нажатой кнопке, а не начинает диалог заново', function () {
        $scenario = publishedMainDialog();
        $session = legacySessionAt($scenario, 'main_menu');

        runMenuAfterBranchMigration();

        $messenger = test()->mock(DereuMessenger::class);
        $messenger->shouldNotReceive('sendText');
        $messenger->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Ремонт. Мастер или ищете?');

        app(BotEngine::class)->handle($session->contact, new InboundMessage(text: 'Ремонт спецтехники', replyId: 'kind_repair'));

        expect($session->fresh()->current_node_id)->toBe('menu_repair');
    });

    test('завершившаяся ветка меню больше не шлёт, а просьба о меню шлёт его сразу', function (AiOutcome $outcome, array $expectedTexts, ?string $expectedNode) {
        $scenario = publishedMainDialog();
        $session = legacySessionAt($scenario, 'search_driver', ['phase' => 'searching']);

        runMenuAfterBranchMigration();

        test()->mock(AiAssistant::class)->shouldReceive('resume')->once()->andReturn($outcome);

        $sent = [];
        $messenger = test()->mock(DereuMessenger::class);
        $messenger->shouldReceive('sendButtons')
            ->andReturnUsing(function (Contact $to, string $text) use (&$sent): void {
                $sent[] = $text;
            });
        $messenger->shouldNotReceive('sendText', 'sendList', 'sendCtaUrl');

        app(BotEngine::class)->handle($session->contact, new InboundMessage(text: 'сообщение'));

        expect($sent)->toBe($expectedTexts)
            ->and($session->fresh()->current_node_id)->toBe($expectedNode);
    })->with([
        'ветка завершилась сама' => [AiOutcome::Completed, [], null],
        'человек попросил меню' => [AiOutcome::Menu, ['Что вас интересует?'], 'main_menu'],
    ]);

    test('до миграции прежняя схема вела себя по-старому — меню уходило и за завершившейся веткой', function () {
        // Контроль: без миграции новое поведение опубликованному сценарию не
        // достаётся, то есть именно она его и приносит.
        $scenario = publishedMainDialog();
        $session = legacySessionAt($scenario, 'search_driver', ['phase' => 'searching']);

        test()->mock(AiAssistant::class)->shouldReceive('resume')->once()->andReturn(AiOutcome::Completed);
        test()->mock(DereuMessenger::class)->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Что вас интересует?');

        app(BotEngine::class)->handle($session->contact, new InboundMessage(text: 'сообщение'));

        expect($session->fresh()->current_node_id)->toBe('main_menu');
    });
});
