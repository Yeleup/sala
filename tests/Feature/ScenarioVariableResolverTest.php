<?php

use App\Enums\ScenarioVariable;
use App\Models\Contact;
use App\Models\Listing;
use App\Models\ScenarioRun;
use App\Services\Bot\ScenarioVariableResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('переменная «Контакт: имя» предпочитает заданное имя имени профиля WhatsApp', function () {
    $run = ScenarioRun::factory()
        ->for(Contact::factory()->state(['profile_name' => 'Асхат', 'display_name' => 'Мағжан']), 'contact')
        ->create();

    expect(app(ScenarioVariableResolver::class)->resolve($run, ScenarioVariable::ContactName))->toBe('Мағжан');
});

test('без заданного имени переменная «Контакт: имя» берёт имя профиля WhatsApp', function () {
    $run = ScenarioRun::factory()
        ->for(Contact::factory()->state(['profile_name' => 'Асхат', 'display_name' => null]), 'contact')
        ->create();

    expect(app(ScenarioVariableResolver::class)->resolve($run, ScenarioVariable::ContactName))->toBe('Асхат');
});

test('переменная «Объявление: название» берёт название объявления', function () {
    $run = ScenarioRun::factory()
        ->for(Listing::factory()->create(['title' => 'Аренда автокрана 25 т']), 'subject')
        ->create();

    expect(app(ScenarioVariableResolver::class)->resolve($run, ScenarioVariable::ListingTitle))->toBe('Аренда автокрана 25 т');
});

test('без названия переменная «Объявление: название» падает на имя категории', function () {
    $run = ScenarioRun::factory()
        ->for(Listing::factory()->create(['title' => null, 'category_id' => categoryNamed('Автокран')->id]), 'subject')
        ->create();

    expect(app(ScenarioVariableResolver::class)->resolve($run, ScenarioVariable::ListingTitle))->toBe('Автокран');
});
