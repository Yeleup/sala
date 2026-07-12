<?php

use App\Models\BotScenario;
use App\Services\Bot\ScenarioDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the installer publishes the reference scenario with every MVP branch', function () {
    $this->artisan('bot:install-default-scenario')->assertSuccessful();

    $scenario = BotScenario::sole();
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
        ->and($definition->startNodeId())->toBe('start');
});

test('the installer refuses to overwrite a published scenario without --force', function () {
    BotScenario::factory()->published()->create();

    $this->artisan('bot:install-default-scenario')->assertFailed();

    expect(BotScenario::sole()->published_version)->toBe(1);
});

test('--force replaces the published scenario with the reference one', function () {
    BotScenario::factory()->published()->create();

    $this->artisan('bot:install-default-scenario', ['--force' => true])->assertSuccessful();

    $scenario = BotScenario::sole();
    expect($scenario->published_version)->toBe(2)
        ->and(collect($scenario->published_definition['nodes'])->pluck('id'))->toContain('customer_search');
});

test('an unpublished draft is replaced without --force', function () {
    BotScenario::factory()->create();

    $this->artisan('bot:install-default-scenario')->assertSuccessful();

    expect(BotScenario::sole()->isPublished())->toBeTrue();
});
