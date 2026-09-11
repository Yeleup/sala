<?php

use App\Enums\ChannelDirection;
use App\Enums\ChannelMessageAuthor;
use App\Enums\ChannelMessageStatus;
use App\Jobs\JournalDereuOperatorEcho;
use App\Models\ChannelMessage;
use App\Models\Contact;
use App\Models\DereuCompany;
use App\Models\DereuWebhookEvent;
use App\Services\OperatorEchoJournal;
use App\Services\OperatorHandoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('сообщение оператора становится строкой переписки со своим автором и своим временем', function () {
    $contact = Contact::factory()->create(['phone' => '77774258186']);
    $event = operatorEchoEvent();

    (new JournalDereuOperatorEcho($event))->handle(app(OperatorEchoJournal::class), app(OperatorHandoff::class));

    $entry = ChannelMessage::sole();

    expect($entry)
        ->contact_id->toBe($contact->id)
        ->direction->toBe(ChannelDirection::Outbound)
        ->author->toBe(ChannelMessageAuthor::Operator)
        ->type->toBe('text')
        ->text->toBe('Ваше объявление загружено! Спасибо')
        ->status->toBe(ChannelMessageStatus::Sent)
        ->dereu_message_id->toBeNull()
        ->estimated_cost_usd->toBeNull()
        ->cost_status->toBeNull()
        // Время отправки, а не время разбора: эхо опаздывает, а бэкофилл
        // пишет позавчерашние сообщения сегодня.
        ->and($entry->created_at->timestamp)->toBe(1788866846)
        ->and($event->fresh()->processed_at)->not->toBeNull();
});

test('повторный прогон джоба не создаёт второй строки', function () {
    Contact::factory()->create(['phone' => '77774258186']);
    $event = operatorEchoEvent();

    (new JournalDereuOperatorEcho($event))->handle(app(OperatorEchoJournal::class), app(OperatorHandoff::class));

    // Джоб уже отработал: повтор (ретрай очереди, бэкофилл) обязан быть
    // холостым, а не удваивать сообщение в переписке.
    $event->update(['processed_at' => null]);
    (new JournalDereuOperatorEcho($event->fresh()))->handle(app(OperatorEchoJournal::class), app(OperatorHandoff::class));

    expect(ChannelMessage::count())->toBe(1);
});

test('исходящее оператора не открывает 24-часовое окно', function () {
    $contact = Contact::factory()->create(['phone' => '77774258186', 'last_inbound_at' => null]);

    (new JournalDereuOperatorEcho(operatorEchoEvent()))->handle(app(OperatorEchoJournal::class), app(OperatorHandoff::class));

    expect($contact->fresh()->last_inbound_at)->toBeNull();
});

test('человек, которому оператор написал первым, заводится контактом с закрытым окном', function () {
    (new JournalDereuOperatorEcho(operatorEchoEvent()))->handle(app(OperatorEchoJournal::class), app(OperatorHandoff::class));

    expect(Contact::sole())
        ->phone->toBe('77774258186')
        ->last_inbound_at->toBeNull();
});

test('голосовое и удаление оператора тоже видны, каждое своим типом', function (array $echo, string $expectedType, ?string $expectedText) {
    Contact::factory()->create(['phone' => '77774258186']);

    (new JournalDereuOperatorEcho(operatorEchoEvent($echo)))->handle(app(OperatorEchoJournal::class), app(OperatorHandoff::class));

    expect(ChannelMessage::sole())
        ->type->toBe($expectedType)
        ->text->toBe($expectedText);
})->with([
    'голосовое' => [
        ['type' => 'audio', 'text' => null, 'audio' => ['id' => '1883842262597556', 'voice' => true, 'mime_type' => 'audio/ogg; codecs=opus']],
        'audio',
        null,
    ],
    'удалённое оператором' => [
        ['type' => 'revoke', 'text' => null, 'revoke' => ['original_message_id' => 'wamid.СТАРОЕ']],
        'revoke',
        null,
    ],
    'интерактив без содержимого' => [
        ['type' => 'interactive', 'text' => null, 'interactive' => ['type' => 'button_reply']],
        'interactive',
        null,
    ],
]);

test('событие чужой компании помечается обработанным и в переписку не попадает', function () {
    config()->set('services.dereu.external_id', 'org_наша');
    DereuCompany::factory()->create(['external_id' => 'org_наша', 'dereu_company_id' => 'co_наша']);

    $event = operatorEchoEvent();
    $event->update(['company_id' => 'co_чужая']);

    (new JournalDereuOperatorEcho($event))->handle(app(OperatorEchoJournal::class), app(OperatorHandoff::class));

    expect(ChannelMessage::count())->toBe(0)
        ->and($event->fresh()->processed_at)->not->toBeNull();
});

test('конверт без пригодного эха не роняет обработку', function (array $payloadPayload) {
    $event = DereuWebhookEvent::query()->create([
        'event' => 'business_app_message_echo',
        'event_id' => (string) Str::ulid(),
        'dedupe_key' => 'event:'.Str::ulid(),
        'company_id' => 'co_abc123',
        'phone_number_id' => '631370540065072',
        'payload' => ['event' => 'business_app_message_echo', 'payload' => $payloadPayload],
    ]);

    (new JournalDereuOperatorEcho($event))->handle(app(OperatorEchoJournal::class), app(OperatorHandoff::class));

    expect(ChannelMessage::count())->toBe(0)
        ->and($event->fresh()->processed_at)->not->toBeNull();
})->with([
    'эха нет вовсе' => [[]],
    'эхо без идентификатора' => [['message_echoes' => [['to' => '77774258186', 'type' => 'text']]]],
    'получателя не разобрать' => [['message_echoes' => [['id' => 'wamid.X', 'to' => 'не телефон', 'type' => 'text']]]],
]);

test('строка бота с тем же wamid эхом оператора не становится', function () {
    // Пространство wamid общее: у строки бота он проставляется позже, из
    // статусного события. Найдись она здесь — пауза «оператор ведёт
    // диалог» включилась бы от сообщения самого бота.
    $contact = Contact::factory()->create(['phone' => '77774258186']);
    $wamid = 'wamid.'.Str::random(24);
    $botRow = ChannelMessage::factory()->outbound()->for($contact)->create(['wamid' => $wamid]);

    (new JournalDereuOperatorEcho(operatorEchoEvent(['id' => $wamid])))
        ->handle(app(OperatorEchoJournal::class), app(OperatorHandoff::class));

    expect(ChannelMessage::count())->toBe(2)
        ->and($botRow->fresh()->author)->toBe(ChannelMessageAuthor::Bot);
});
