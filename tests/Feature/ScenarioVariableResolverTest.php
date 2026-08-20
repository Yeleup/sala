<?php

use App\Enums\ScenarioVariable;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\ListingRenewalBatch;
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

test('ни одна переменная не резолвится в пустую строку', function (ScenarioVariable $variable) {
    // Meta отклоняет шаблон с пустым текстовым параметром — пустая
    // переменная убила бы отправку целиком. Даже на голых данных
    // (контакт без имени, объявление без описания/места/цены, заявка
    // без запроса) каждая переменная обязана дать непустую подстановку.
    $run = ScenarioRun::factory()
        ->for(Contact::factory()->state(['profile_name' => null, 'display_name' => null]), 'contact')
        ->for(CustomerRequest::factory()->state(['query_text' => ''])->for(
            Listing::factory()->create([
                'title' => null,
                'category_id' => null,
                'description' => null,
                'location_id' => null,
                'price' => null,
            ]),
            'listing',
        ), 'subject')
        ->create();

    expect(app(ScenarioVariableResolver::class)->resolve($run, $variable))->not->toBe('');
})->with(ScenarioVariable::cases());

test('неизвестный ключ переменной даёт прочерк, а не пустой параметр', function () {
    $run = ScenarioRun::factory()->create();

    expect(app(ScenarioVariableResolver::class)->values($run, ['no.such.key']))->toBe(['—']);
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

describe('переменные пачки истекающих объявлений', function () {
    function batchRun(array $titles): ScenarioRun
    {
        $supplier = Contact::factory()->create();
        $batch = ListingRenewalBatch::query()->create(['contact_id' => $supplier->id]);

        foreach ($titles as $title) {
            $batch->listings()->attach(
                Listing::factory()->published()->for($supplier, 'supplier')
                    ->create(['title' => $title, 'category_id' => null, 'expires_at' => now()->addHours(10)])->id,
            );
        }

        return ScenarioRun::factory()->for($supplier, 'contact')->for($batch, 'subject')->create();
    }

    test('пачка называет объявление и говорит, сколько ещё', function () {
        $run = batchRun(['Автокран 25 т', 'Экскаватор Hitachi', 'Самосвал 20 т']);
        $resolver = app(ScenarioVariableResolver::class);

        expect($resolver->resolve($run, ScenarioVariable::ExpiringListingsFirst))->toBe('Автокран 25 т')
            ->and($resolver->resolve($run, ScenarioVariable::ExpiringListingsRest))->toBe('2 объявления');
    });

    test('безымянное объявление пропускается, а не подставляется как «без названия»', function () {
        $run = batchRun([null, 'Экскаватор Hitachi']);

        expect(app(ScenarioVariableResolver::class)->resolve($run, ScenarioVariable::ExpiringListingsFirst))
            ->toBe('Экскаватор Hitachi');
    });

    test('остаток в одно объявление читается словом, а не цифрой', function () {
        $run = batchRun(['Автокран 25 т', 'Экскаватор Hitachi']);

        expect(app(ScenarioVariableResolver::class)->resolve($run, ScenarioVariable::ExpiringListingsRest))
            ->toBe('одно объявление');
    });

    test('снятый ключ «listings.expiring» отдаёт прочерк, а не роняет отправку', function () {
        // Опубликованные до переезда версии сценария закреплены за старым
        // ключом: Meta отвергает шаблон с пустым параметром, поэтому дыра
        // обязана заполняться, а не оставаться пустой.
        $run = batchRun(['Автокран 25 т', 'Экскаватор Hitachi']);

        expect(app(ScenarioVariableResolver::class)->values($run, ['listings.expiring']))->toBe(['—']);
    });
});
