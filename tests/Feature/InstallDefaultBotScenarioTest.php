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

    expect($nodes->firstWhere('id', 'main_menu')['options'])->toHaveCount(3)
        ->and($nodes->firstWhere('id', 'collect_equipment'))
        ->toMatchArray(['task' => 'collect_listing', 'listing_type' => 'equipment'])
        ->and($nodes->firstWhere('id', 'collect_service'))
        ->toMatchArray(['task' => 'collect_listing', 'listing_type' => 'service'])
        ->and($nodes->firstWhere('id', 'customer_search')['task'])->toBe('customer_search')
        ->and($nodes->firstWhere('id', 'my_listings')['type'])->toBe('my_listings')
        ->and($definition->startNodeId())->toBe('start')
        // Повторное обращение ведёт сразу к меню, минуя приветствие.
        ->and($definition->target('start', ScenarioDefinition::OUTPUT_RETURNING))->toBe('main_menu')
        ->and($definition->target('start', ScenarioDefinition::OUTPUT_CONTINUE))->toBe('greeting');
});

test('the installer publishes the flow scenarios next to the main dialog', function () {
    $this->artisan('bot:install-default-scenario')->assertSuccessful();

    expect(BotScenario::query()->count())->toBe(3);

    $request = BotScenario::publishedForTrigger(BotScenarioTrigger::NewCustomerRequest);
    $requestNodes = collect($request->published_definition['nodes']);

    expect($requestNodes->firstWhere('id', 'poll'))
        ->toMatchArray(['type' => 'message', 'channel' => 'adaptive', 'template_name' => 'new_customer_request'])
        ->and($requestNodes->firstWhere('id', 'poll')['variables'])->toBe(['listing.category', 'request.query'])
        ->and($requestNodes->firstWhere('id', 'do_accept')['action'])->toBe('accept_request')
        ->and($requestNodes->firstWhere('id', 'check_accept')['condition'])->toBe('request_pending');

    $renewal = BotScenario::publishedForTrigger(BotScenarioTrigger::ListingExpiring);
    $renewalNodes = collect($renewal->published_definition['nodes']);

    expect($renewalNodes->firstWhere('id', 'poll')['template_name'])->toBe('listing_renewal')
        ->and($renewalNodes->firstWhere('id', 'do_renew')['action'])->toBe('renew_listing')
        ->and($renewalNodes->firstWhere('id', 'do_archive')['action'])->toBe('archive_listing');
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
        ->and(collect($scenario->published_definition['nodes'])->pluck('id'))->toContain('customer_search');
});

test('an unpublished draft is replaced without --force', function () {
    BotScenario::factory()->create();

    $this->artisan('bot:install-default-scenario')->assertSuccessful();

    expect(BotScenario::main()->isPublished())->toBeTrue();
});
