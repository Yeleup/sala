<?php

use App\Models\Contact;
use App\Services\DereuMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.dereu.external_id', 'org_test');
    config()->set('services.dereu.base_url', 'https://api.dereu.test/api/v1');

    Http::preventStrayRequests();
});

function fakeDereuSendAccepted(): void
{
    Http::fake(['api.dereu.test/*' => Http::response(['id' => 'uuid', 'status' => 'queued'], 202)]);
}

test('a text message is sent with the company key and a normalized recipient', function () {
    fakeDereuSendAccepted();
    connectedDereuCompany(['phone_number_id' => '1234567890', 'api_key' => 'dereu_testkey']);
    $contact = Contact::factory()->create(['phone' => '77011234567']);

    app(DereuMessenger::class)->sendText($contact, 'Привет!');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.dereu.test/api/v1/messages/send'
        && $request->hasHeader('Authorization', 'Bearer dereu_testkey')
        && $request['phone_number_id'] === '1234567890'
        && $request['to'] === '+77011234567'
        && $request['type'] === 'text'
        && $request['payload'] === ['body' => 'Привет!']);
});

test('a recipient phone that already has a plus is not doubled', function () {
    fakeDereuSendAccepted();
    connectedDereuCompany();
    $contact = Contact::factory()->create(['phone' => '+77011234567']);

    app(DereuMessenger::class)->sendText($contact, 'Привет!');

    Http::assertSent(fn (Request $request) => $request['to'] === '+77011234567');
});

test('buttons are sent as an interactive button message', function () {
    fakeDereuSendAccepted();
    connectedDereuCompany();
    $contact = Contact::factory()->create();

    app(DereuMessenger::class)->sendButtons($contact, 'Кто вы?', [
        ['id' => 'supplier', 'title' => 'Поставщик'],
        ['id' => 'customer', 'title' => 'Заказчик'],
    ]);

    Http::assertSent(fn (Request $request) => $request['type'] === 'interactive'
        && $request['payload']['type'] === 'button'
        && $request['payload']['body'] === ['text' => 'Кто вы?']
        && $request['payload']['action']['buttons'] === [
            ['type' => 'reply', 'reply' => ['id' => 'supplier', 'title' => 'Поставщик']],
            ['type' => 'reply', 'reply' => ['id' => 'customer', 'title' => 'Заказчик']],
        ]);
});

test('a list is sent as an interactive list message with a single section', function () {
    fakeDereuSendAccepted();
    connectedDereuCompany();
    $contact = Contact::factory()->create();

    app(DereuMessenger::class)->sendList($contact, 'Выберите категорию', 'Категории', [
        ['id' => 'crane', 'title' => 'Кран'],
        ['id' => 'excavator', 'title' => 'Экскаватор'],
    ]);

    Http::assertSent(fn (Request $request) => $request['type'] === 'interactive'
        && $request['payload']['type'] === 'list'
        && $request['payload']['body'] === ['text' => 'Выберите категорию']
        && $request['payload']['action']['button'] === 'Категории'
        && $request['payload']['action']['sections'] === [
            ['rows' => [
                ['id' => 'crane', 'title' => 'Кран'],
                ['id' => 'excavator', 'title' => 'Экскаватор'],
            ]],
        ]);
});

test('sending fails when no company is connected', function () {
    Http::fake();
    $contact = Contact::factory()->create();

    expect(fn () => app(DereuMessenger::class)->sendText($contact, 'Привет!'))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

test('sending fails when the connected company has no api key', function () {
    Http::fake();
    connectedDereuCompany(['api_key' => null]);
    $contact = Contact::factory()->create();

    expect(fn () => app(DereuMessenger::class)->sendText($contact, 'Привет!'))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

test('a rejected send surfaces as an exception', function () {
    Http::fake(['api.dereu.test/*' => Http::response(['error' => 'validation'], 422)]);
    connectedDereuCompany();
    $contact = Contact::factory()->create();

    expect(fn () => app(DereuMessenger::class)->sendText($contact, 'Привет!'))
        ->toThrow(RequestException::class);
});
