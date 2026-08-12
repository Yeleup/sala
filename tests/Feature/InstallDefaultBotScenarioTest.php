<?php

use App\Enums\BotScenarioTrigger;
use App\Models\BotScenario;
use App\Services\Bot\ScenarioDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the installer publishes the reference main dialog with every MVP branch', function () {
    $this->artisan('bot:install-default-scenario')->assertSuccessful();

    $scenario = BotScenario::main();
    expect($scenario->isPublished())->toBeTrue();

    $definition = new ScenarioDefinition($scenario->published_definition);
    $nodes = collect($scenario->published_definition['nodes']);

    expect($nodes->firstWhere('id', 'main_menu')['options'])->toHaveCount(7)
        ->and($nodes->firstWhere('id', 'collect_rental')['task'])->toBe('collect_listing')
        ->and($nodes->firstWhere('id', 'search_rental')['task'])->toBe('customer_search')
        ->and($nodes->firstWhere('id', 'my_listings')['type'])->toBe('my_listings')
        ->and($definition->startNodeId())->toBe('start')
        // Повторное обращение ведёт сразу к меню, минуя приветствие.
        ->and($definition->target('start', ScenarioDefinition::OUTPUT_RETURNING))->toBe('main_menu')
        ->and($definition->target('start', ScenarioDefinition::OUTPUT_CONTINUE))->toBe('greeting')
        // Каждая ветка меню подключена: поставщик попадает к сборщику сразу.
        ->and($definition->target('main_menu', 'option:rent_out'))->toBe('collect_rental')
        ->and($definition->target('main_menu', 'option:rent_seek'))->toBe('search_rental')
        ->and($definition->target('main_menu', 'option:my'))->toBe('my_listings');
});

test('типовой главный сценарий — меню-список из шести веток с видами на AI-узлах', function () {
    $this->artisan('bot:install-default-scenario --force')->assertSuccessful();

    $scenario = BotScenario::main();
    $definition = new ScenarioDefinition($scenario->published_definition);
    $nodes = collect($scenario->published_definition['nodes']);
    $menu = $nodes->firstWhere('id', 'main_menu');

    // Шесть веток видов плюс «Мои объявления» седьмой строкой (лимит WhatsApp — 10).
    expect($menu['type'])->toBe('list')
        ->and(collect($menu['options'])->pluck('id')->all())
        ->toBe(['rent_out', 'rent_seek', 'master', 'master_seek', 'driver', 'driver_seek', 'my']);

    foreach ([
        'collect_rental' => ['collect_listing', 'rental'],
        'collect_repair' => ['collect_listing', 'repair'],
        'collect_driver' => ['collect_listing', 'driver'],
        'search_rental' => ['customer_search', 'rental'],
        'search_repair' => ['customer_search', 'repair'],
        'search_driver' => ['customer_search', 'driver'],
    ] as $id => [$task, $kind]) {
        // У каждой ветки — своя пара задача+вид, и выход «Продолжить» подключен.
        expect($nodes->firstWhere('id', $id))->toMatchArray(['task' => $task, 'kind' => $kind])
            ->and($definition->target($id, ScenarioDefinition::OUTPUT_CONTINUE))->not->toBeNull();
    }
});

test('the installer publishes the flow scenarios next to the main dialog', function () {
    $this->artisan('bot:install-default-scenario')->assertSuccessful();

    expect(BotScenario::query()->count())->toBe(3);

    $request = BotScenario::publishedForTrigger(BotScenarioTrigger::NewCustomerRequest);
    $requestNodes = collect($request->published_definition['nodes']);
    $requestDefinition = new ScenarioDefinition($request->published_definition);

    expect($requestNodes->firstWhere('id', 'poll'))
        ->toMatchArray(['type' => 'message', 'channel' => 'adaptive', 'template_name' => 'new_customer_request'])
        ->and($requestNodes->firstWhere('id', 'poll')['variables'])->toBe(['listing.title', 'request.query'])
        ->and($requestNodes->firstWhere('id', 'do_accept')['action'])->toBe('accept_request')
        // Ветвится сам исход действия — отдельных блоков «Условие» больше нет.
        ->and($requestNodes->where('type', 'condition'))->toBeEmpty()
        ->and($requestDefinition->target('do_accept', ScenarioDefinition::OUTPUT_CONTINUE))->toBe('accepted_text')
        ->and($requestDefinition->target('do_accept', ScenarioDefinition::OUTPUT_SKIPPED))->toBe('already_decided')
        ->and($requestDefinition->target('do_decline', ScenarioDefinition::OUTPUT_SKIPPED))->toBe('already_decided');

    $renewal = BotScenario::publishedForTrigger(BotScenarioTrigger::ListingExpiring);
    $renewalNodes = collect($renewal->published_definition['nodes']);
    $renewalDefinition = new ScenarioDefinition($renewal->published_definition);

    expect($renewalNodes->firstWhere('id', 'poll')['template_name'])->toBe('listing_renewal')
        ->and($renewalNodes->firstWhere('id', 'do_renew')['action'])->toBe('renew_listing')
        ->and($renewalNodes->firstWhere('id', 'do_archive')['action'])->toBe('archive_listing')
        ->and($renewalNodes->where('type', 'condition'))->toBeEmpty()
        ->and($renewalDefinition->target('do_renew', ScenarioDefinition::OUTPUT_SKIPPED))->toBe('already_archived')
        ->and($renewalDefinition->target('do_archive', ScenarioDefinition::OUTPUT_SKIPPED))->toBe('already_archived');
});

test('the installer refuses to overwrite a published scenario without --force', function () {
    BotScenario::factory()->published()->create();

    $this->artisan('bot:install-default-scenario')->assertFailed();

    expect(BotScenario::main()->published_version)->toBe(1);
});

test('--force replaces the published scenario with the reference one', function () {
    BotScenario::factory()->published()->create();

    $this->artisan('bot:install-default-scenario', ['--force' => true])->assertSuccessful();

    $scenario = BotScenario::main();
    expect($scenario->published_version)->toBe(2)
        ->and(collect($scenario->published_definition['nodes'])->pluck('id'))->toContain('search_rental');
});

test('an unpublished draft is replaced without --force', function () {
    BotScenario::factory()->create();

    $this->artisan('bot:install-default-scenario')->assertSuccessful();

    expect(BotScenario::main()->isPublished())->toBeTrue();
});
