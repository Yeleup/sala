<?php

use App\Enums\ScenarioVariable;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\ScenarioRun;
use App\Services\Bot\ScenarioVariableResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('переменная «Получатель: имя» предпочитает заданное имя имени профиля WhatsApp', function () {
    $run = ScenarioRun::factory()
        ->for(Contact::factory()->state(['profile_name' => 'Асхат', 'display_name' => 'Мағжан']), 'contact')
        ->create();

    expect(app(ScenarioVariableResolver::class)->resolve($run, ScenarioVariable::ContactName))->toBe('Мағжан');
});

test('без заданного имени переменная «Получатель: имя» берёт имя профиля WhatsApp', function () {
    $run = ScenarioRun::factory()
        ->for(Contact::factory()->state(['profile_name' => 'Асхат', 'display_name' => null]), 'contact')
        ->create();

    expect(app(ScenarioVariableResolver::class)->resolve($run, ScenarioVariable::ContactName))->toBe('Асхат');
});

test('переменная «Заявка: заказчик» даёт имя и телефон автора заявки, а не получателя уведомления', function () {
    $run = ScenarioRun::factory()
        ->for(Contact::factory()->state(['profile_name' => 'Поставщик', 'phone' => '77073335092']), 'contact')
        ->for(CustomerRequest::factory()->for(
            Contact::factory()->state(['profile_name' => 'Асель Омирзак', 'display_name' => null, 'phone' => '77000930522']),
            'customer',
        ), 'subject')
        ->create();

    expect(app(ScenarioVariableResolver::class)->resolve($run, ScenarioVariable::RequestCustomer))
        ->toBe('Асель Омирзак, +77000930522');
});

test('длинное имя заказчика обрезается, но телефон в переменной сохраняется целиком', function () {
    $run = ScenarioRun::factory()
        ->for(CustomerRequest::factory()->for(
            Contact::factory()->state(['display_name' => str_repeat('А', 250), 'phone' => '77000930522']),
            'customer',
        ), 'subject')
        ->create();

    $value = app(ScenarioVariableResolver::class)->resolve($run, ScenarioVariable::RequestCustomer);

    expect($value)->toEndWith(', +77000930522')
        ->and(mb_strlen($value))->toBeLessThanOrEqual(200);
});

test('без имени заказчика переменная «Заявка: заказчик» подставляет только телефон', function () {
    $run = ScenarioRun::factory()
        ->for(CustomerRequest::factory()->for(
            Contact::factory()->state(['profile_name' => null, 'display_name' => null, 'phone' => '77000930522']),
            'customer',
        ), 'subject')
        ->create();

    expect(app(ScenarioVariableResolver::class)->resolve($run, ScenarioVariable::RequestCustomer))
        ->toBe('+77000930522');
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
