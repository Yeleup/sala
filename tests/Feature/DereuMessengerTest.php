<?php

use App\Enums\ChannelDirection;
use App\Enums\ChannelMessageStatus;
use App\Exceptions\OutboundRequestBlocked;
use App\Exceptions\SessionWindowClosed;
use App\Models\ChannelMessage;
use App\Models\Contact;
use App\Models\WhatsappTemplate;
use App\Services\DereuMessenger;
use App\Services\TemplateFallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.dereu.external_id', 'org_test');
    config()->set('services.dereu.base_url', 'https://api.dereu.test/api/v1');

    Http::preventStrayRequests();
});

function fakeDereuSendAccepted(): void
{
    // Каждому запросу — свой id: журнал channel_messages держит уникальность
    // по dereu_message_id, как и реальный Dereu.
    Http::fake([
        'api.dereu.test/*' => fn () => Http::response(
            ['id' => (string) Str::uuid(), 'status' => 'queued'],
            202,
        ),
    ]);
}

test('a text message is sent with the company key and a normalized recipient', function () {
    fakeDereuSendAccepted();
    connectedDereuCompany(['phone_number_id' => '1234567890', 'api_key' => 'dereu_testkey']);
    $contact = Contact::factory()->withOpenSessionWindow()->create(['phone' => '77011234567']);

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
    $contact = Contact::factory()->withOpenSessionWindow()->create(['phone' => '+77011234567']);

    app(DereuMessenger::class)->sendText($contact, 'Привет!');

    Http::assertSent(fn (Request $request) => $request['to'] === '+77011234567');
});

test('buttons are sent as an interactive button message', function () {
    fakeDereuSendAccepted();
    connectedDereuCompany();
    $contact = Contact::factory()->withOpenSessionWindow()->create();

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

test('a web handoff link is sent as an interactive cta_url message', function () {
    fakeDereuSendAccepted();
    connectedDereuCompany();
    $contact = Contact::factory()->withOpenSessionWindow()->create();

    app(DereuMessenger::class)->sendCtaUrl($contact, 'Откройте форму', 'Открыть', 'https://app.test/supplier/listings/1/edit?signature=abc');

    Http::assertSent(fn (Request $request) => $request['type'] === 'interactive'
        && $request['payload']['type'] === 'cta_url'
        && $request['payload']['body'] === ['text' => 'Откройте форму']
        && $request['payload']['action'] === [
            'name' => 'cta_url',
            'parameters' => ['display_text' => 'Открыть', 'url' => 'https://app.test/supplier/listings/1/edit?signature=abc'],
        ]);
});

test('a list is sent as an interactive list message with a single section', function () {
    fakeDereuSendAccepted();
    connectedDereuCompany();
    $contact = Contact::factory()->withOpenSessionWindow()->create();

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

test('an interactive list clamps oversized fields to WhatsApp limits, ellipsis included', function () {
    fakeDereuSendAccepted();
    connectedDereuCompany();
    $contact = Contact::factory()->withOpenSessionWindow()->create();

    $longTitle = str_repeat('Экскаваторы гусеничные ', 3); // well over 24 chars, multibyte
    $longDescription = str_repeat('Алматы, ул. Абая, ', 6); // well over 72 chars, multibyte

    app(DereuMessenger::class)->sendList($contact, 'Выберите категорию', str_repeat('Категории техники ', 3), [
        ['id' => str_repeat('x', 250), 'title' => $longTitle, 'description' => $longDescription],
    ]);

    Http::assertSent(function (Request $request) {
        $row = $request['payload']['action']['sections'][0]['rows'][0];

        expect(mb_strlen($row['id']))->toBeLessThanOrEqual(200)
            ->and(mb_strlen($row['title']))->toBeLessThanOrEqual(24)
            ->and($row['title'])->toEndWith('…')
            ->and(mb_strlen($row['description']))->toBeLessThanOrEqual(72)
            ->and($row['description'])->toEndWith('…')
            ->and(mb_strlen($request['payload']['action']['button']))->toBeLessThanOrEqual(20);

        return true;
    });
});

test('interactive buttons clamp oversized titles and body to WhatsApp limits', function () {
    fakeDereuSendAccepted();
    connectedDereuCompany();
    $contact = Contact::factory()->withOpenSessionWindow()->create();

    $longBody = str_repeat('Уточните, пожалуйста, ваш выбор из предложенных вариантов. ', 30);
    $longTitle = 'Очень длинное название кнопки, которое точно превышает лимит';

    app(DereuMessenger::class)->sendButtons($contact, $longBody, [
        ['id' => 'supplier', 'title' => $longTitle],
    ]);

    Http::assertSent(function (Request $request) {
        expect(mb_strlen($request['payload']['body']['text']))->toBeLessThanOrEqual(1024);

        $reply = $request['payload']['action']['buttons'][0]['reply'];
        expect(mb_strlen($reply['title']))->toBeLessThanOrEqual(20)
            ->and($reply['title'])->toEndWith('…');

        return true;
    });
});

test('a cta_url button text is clamped to the WhatsApp limit', function () {
    fakeDereuSendAccepted();
    connectedDereuCompany();
    $contact = Contact::factory()->withOpenSessionWindow()->create();

    app(DereuMessenger::class)->sendCtaUrl($contact, 'Откройте форму', 'Очень длинный текст кнопки', 'https://app.test/form');

    Http::assertSent(function (Request $request) {
        $displayText = $request['payload']['action']['parameters']['display_text'];
        expect(mb_strlen($displayText))->toBeLessThanOrEqual(20)
            ->and($displayText)->toEndWith('…');

        return true;
    });
});

test('short interactive texts are left untouched', function () {
    fakeDereuSendAccepted();
    connectedDereuCompany();
    $contact = Contact::factory()->withOpenSessionWindow()->create();

    app(DereuMessenger::class)->sendList($contact, 'Выберите', 'Категории', [
        ['id' => 'crane', 'title' => 'Кран', 'description' => 'Алматы'],
    ]);

    Http::assertSent(fn (Request $request) => $request['payload']['action']['sections'][0]['rows'][0] === [
        'id' => 'crane',
        'title' => 'Кран',
        'description' => 'Алматы',
    ]);
});

test('sending fails when no company is connected', function () {
    Http::fake();
    $contact = Contact::factory()->withOpenSessionWindow()->create();

    expect(fn () => app(DereuMessenger::class)->sendText($contact, 'Привет!'))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

test('sending fails when the connected company has no api key', function () {
    Http::fake();
    connectedDereuCompany(['api_key' => null]);
    $contact = Contact::factory()->withOpenSessionWindow()->create();

    expect(fn () => app(DereuMessenger::class)->sendText($contact, 'Привет!'))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

test('a rejected send surfaces as an exception', function () {
    Http::fake(['api.dereu.test/*' => Http::response(['error' => 'validation'], 422)]);
    connectedDereuCompany();
    $contact = Contact::factory()->withOpenSessionWindow()->create();

    expect(fn () => app(DereuMessenger::class)->sendText($contact, 'Привет!'))
        ->toThrow(RequestException::class);
});

test('a rejected send leaves a failed entry in the channel journal', function () {
    Http::fake(['api.dereu.test/*' => Http::response(['error' => 'validation'], 422)]);
    connectedDereuCompany();
    $contact = Contact::factory()->withOpenSessionWindow()->create();

    expect(fn () => app(DereuMessenger::class)->sendText($contact, 'Привет!'))
        ->toThrow(RequestException::class);

    // Без записи диалог в операторском «Чате» выглядит так, будто бот
    // просто молчал: инцидент (протухший ключ, невалидный payload) ищется
    // вслепую по laravel-логам, а счётчик ошибок показывает ноль.
    $entry = ChannelMessage::sole();
    expect($entry->status)->toBe(ChannelMessageStatus::Failed)
        ->and($entry->direction)->toBe(ChannelDirection::Outbound)
        ->and($entry->text)->toBe('Привет!')
        ->and($entry->failure_reason)->not->toBeNull();
});

test('a rejected send is logged at the error level, not a warning', function () {
    Http::fake(['api.dereu.test/*' => Http::response(['error' => 'rate limited'], 429)]);
    connectedDereuCompany();
    $contact = Contact::factory()->withOpenSessionWindow()->create();
    Log::spy();

    expect(fn () => app(DereuMessenger::class)->sendText($contact, 'Привет!'))
        ->toThrow(RequestException::class);

    // Мониторинг считает только error-строки: синхронный 429/5xx от Dereu,
    // оставлявший после себя лишь warning, для него не существовал — при
    // том что это тот же «бот замолчал», что и асинхронный отказ Meta.
    Log::shouldHaveReceived('error')->once();
});

test('a session message outside the 24-hour window is refused locally', function () {
    Http::fake();
    connectedDereuCompany();
    $contact = Contact::factory()->withClosedSessionWindow()->create();

    expect(fn () => app(DereuMessenger::class)->sendText($contact, 'Привет!'))
        ->toThrow(SessionWindowClosed::class);

    // Пре-флайт отказ — не попытка отправки: sendTextOrTemplate и
    // нотификаторы используют его как штатное ветвление на шаблон, и
    // журналировать его «ошибкой» значило бы засорять чат ложными сбоями.
    Http::assertNothingSent();
    expect(ChannelMessage::count())->toBe(0);
});

test('a session send records its template fallback in the journal', function () {
    fakeDereuSendAccepted();
    connectedDereuCompany();
    $contact = Contact::factory()->withOpenSessionWindow()->create();
    $template = WhatsappTemplate::factory()->approved()->create();

    // План Б на асинхронный отказ Meta: если сессионное сообщение умрёт
    // как «вне окна», обработчик message_failed перепошлёт его шаблоном —
    // для этого журнальная строка несёт всё нужное для отправки шаблона.
    app(DereuMessenger::class)->sendButtons(
        $contact,
        'Новая заявка. Готовы взять заказ?',
        [['id' => 'req:1:accept', 'title' => 'Согласиться']],
        fallback: new TemplateFallback($template, ['Автокран 25т'], ['req:1:accept']),
    );

    expect(ChannelMessage::sole()->template_fallback)->toBe([
        'whatsapp_template_id' => $template->id,
        'body_parameters' => ['Автокран 25т'],
        'button_payloads' => ['req:1:accept'],
    ]);
});

test('sendTextOrTemplate inside the window records the template as its own fallback', function () {
    fakeDereuSendAccepted();
    connectedDereuCompany();
    $contact = Contact::factory()->withOpenSessionWindow()->create();
    $template = WhatsappTemplate::factory()->approved()->create();

    app(DereuMessenger::class)->sendTextOrTemplate($contact, 'Объявление скоро истечёт', $template, ['Автокран']);

    expect(ChannelMessage::sole()->template_fallback)->toBe([
        'whatsapp_template_id' => $template->id,
        'body_parameters' => ['Автокран'],
        'button_payloads' => [],
    ]);
});

test('a template send never carries a fallback of its own', function () {
    fakeDereuSendAccepted();
    connectedDereuCompany();
    $contact = Contact::factory()->withClosedSessionWindow()->create();
    $template = WhatsappTemplate::factory()->approved()->create();

    app(DereuMessenger::class)->sendTemplate($contact, $template, ['x']);

    // Отсутствие фолбэка у шаблонных отправок — защита от цикла: упавший
    // шаблон не может породить ещё одну переотправку.
    expect(ChannelMessage::sole()->template_fallback)->toBeNull();
});

test('an approved template is sent with body parameters regardless of the window', function () {
    fakeDereuSendAccepted();
    connectedDereuCompany();
    $contact = Contact::factory()->withClosedSessionWindow()->create();
    $template = WhatsappTemplate::factory()->approved()->create(['name' => 'listing_renewal', 'language' => 'ru']);

    app(DereuMessenger::class)->sendTemplate($contact, $template, ['Автокран 25т']);

    Http::assertSent(fn (Request $request) => $request['type'] === 'template'
        && $request['payload']['name'] === 'listing_renewal'
        && $request['payload']['language'] === ['code' => 'ru']
        && $request['payload']['components'] === [[
            'type' => 'body',
            'parameters' => [['type' => 'text', 'text' => 'Автокран 25т']],
        ]]);
});

test('template body parameters are stripped of newlines and space runs before the send', function () {
    fakeDereuSendAccepted();
    connectedDereuCompany();
    $contact = Contact::factory()->withClosedSessionWindow()->create();
    $template = WhatsappTemplate::factory()->approved()->create(['name' => 'listing_renewal', 'language' => 'ru']);

    // Meta отклоняет параметры с переводами строк, табами и сериями
    // пробелов — свободный текст (название, запрос) обязан быть очищен.
    app(DereuMessenger::class)->sendTemplate($contact, $template, ["Аренда\nкрана\t25     т "]);

    Http::assertSent(fn (Request $request) => $request['type'] === 'template'
        && $request['payload']['components'][0]['parameters'] === [['type' => 'text', 'text' => 'Аренда крана 25 т']]);
});

test('an unapproved template cannot be sent', function () {
    Http::fake();
    connectedDereuCompany();
    $contact = Contact::factory()->withClosedSessionWindow()->create();
    $template = WhatsappTemplate::factory()->create();

    expect(fn () => app(DereuMessenger::class)->sendTemplate($contact, $template, []))
        ->toThrow(RuntimeException::class, 'not approved');

    Http::assertNothingSent();
});

test('the channel is chosen by the window: session text inside, template outside', function () {
    fakeDereuSendAccepted();
    connectedDereuCompany();
    $template = WhatsappTemplate::factory()->approved()->create();

    $openContact = Contact::factory()->withOpenSessionWindow()->create();
    app(DereuMessenger::class)->sendTextOrTemplate($openContact, 'Объявление скоро истечёт', $template, ['x']);
    Http::assertSent(fn (Request $request) => $request['type'] === 'text'
        && $request['payload'] === ['body' => 'Объявление скоро истечёт']);

    $closedContact = Contact::factory()->withClosedSessionWindow()->create();
    app(DereuMessenger::class)->sendTextOrTemplate($closedContact, 'Объявление скоро истечёт', $template, ['x']);
    Http::assertSent(fn (Request $request) => $request['type'] === 'template'
        && $request['payload']['name'] === $template->name);
});

test('a send the local guard blocked is not journalled as a delivery failure', function () {
    config()->set('services.dereu.base_url', 'https://api.dereu.example/api/v1');
    app()->instance('env', 'local');

    connectedDereuCompany(['api_key' => 'dereu_testkey']);
    $contact = Contact::factory()->withOpenSessionWindow()->create(['phone' => '77011234567']);

    expect(fn () => app(DereuMessenger::class)->sendText($contact, 'Привет!'))
        ->toThrow(OutboundRequestBlocked::class);

    expect(ChannelMessage::query()->count())->toBe(0);
});

test('a send the local guard blocked is not counted as a dead channel', function () {
    config()->set('services.dereu.base_url', 'https://api.dereu.example/api/v1');
    app()->instance('env', 'local');

    Log::spy();
    connectedDereuCompany(['api_key' => 'dereu_testkey']);
    $contact = Contact::factory()->withOpenSessionWindow()->create(['phone' => '77011234567']);

    expect(fn () => app(DereuMessenger::class)->sendText($contact, 'Привет!'))
        ->toThrow(OutboundRequestBlocked::class);

    Log::shouldNotHaveReceived('error');
});
