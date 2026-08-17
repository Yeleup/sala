<?php

use App\Enums\ChannelDirection;
use App\Enums\ChannelMessageStatus;
use App\Jobs\ProcessDereuWebhookEvent;
use App\Models\ChannelMessage;
use App\Models\Contact;
use App\Models\DereuWebhookEvent;
use App\Models\WhatsappTemplate;
use App\Services\DereuMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.dereu.webhook_secret', 'whsec_test');
    config()->set('services.dereu.external_id', 'org_test');
    config()->set('services.dereu.base_url', 'https://api.dereu.test/api/v1');
    // Джобы вебхука должны исполниться в самом тесте (контейнерное окружение
    // перебивает phpunit.xml, поэтому очередь переключается здесь).
    config()->set('queue.default', 'sync');
});

function signedDereuEvent(array $overrides = []): void
{
    $payload = array_merge([
        'event' => 'message_received',
        'event_id' => (string) Str::ulid(),
        'company_id' => 'co_abc123',
        'phone_number_id' => '1234567890',
        'from' => '77011234567',
        'wamid' => 'wamid.'.Str::random(12),
        'type' => 'text',
        'payload' => ['body' => 'Привет'],
        'timestamp' => 1718000000,
    ], $overrides);

    test()->postJson(route('webhooks.dereu'), $payload, [
        'X-Dereu-Signature' => 'sha256='.hash_hmac('sha256', json_encode($payload), 'whsec_test'),
    ])->assertNoContent();
}

describe('исходящие в журнале', function () {
    test('отправка пишет строку журнала с uuid Dereu и статусом «в очереди»', function () {
        connectedDereuCompany(['phone_number_id' => '1234567890']);
        $contact = Contact::factory()->withOpenSessionWindow()->create();
        $uuid = (string) Str::uuid();
        Http::fake(['api.dereu.test/api/v1/messages/send' => Http::response(['id' => $uuid, 'status' => 'queued'], 202)]);

        app(DereuMessenger::class)->sendText($contact, 'Здравствуйте!');

        $entry = ChannelMessage::sole();
        expect($entry)
            ->direction->toBe(ChannelDirection::Outbound)
            ->type->toBe('text')
            ->text->toBe('Здравствуйте!')
            ->dereu_message_id->toBe($uuid)
            ->status->toBe(ChannelMessageStatus::Queued)
            ->contact_id->toBe($contact->id);
    });

    test('отказ Dereu оставляет в журнале строку со статусом «ошибка»', function () {
        // Раньше упавшая отправка не журналировалась вовсе — в «Чате»
        // оператора бот выглядел просто молчащим, а счётчик ошибок
        // показывал ноль при полностью лежащем канале.
        connectedDereuCompany(['phone_number_id' => '1234567890']);
        $contact = Contact::factory()->withOpenSessionWindow()->create();
        Http::fake(['api.dereu.test/api/v1/messages/send' => Http::response(['message' => 'invalid'], 422)]);

        expect(fn () => app(DereuMessenger::class)->sendText($contact, 'Привет'))
            ->toThrow(RequestException::class);

        $entry = ChannelMessage::sole();
        expect($entry)
            ->direction->toBe(ChannelDirection::Outbound)
            ->status->toBe(ChannelMessageStatus::Failed)
            ->text->toBe('Привет')
            ->failure_reason->not->toBeNull();
    });
});

describe('входящие в журнале', function () {
    test('входящее сообщение попадает в журнал с wamid и текстом', function () {
        signedDereuEvent(['wamid' => 'wamid.inbound-1']);

        $entry = ChannelMessage::sole();
        expect($entry)
            ->direction->toBe(ChannelDirection::Inbound)
            ->status->toBe(ChannelMessageStatus::Received)
            ->wamid->toBe('wamid.inbound-1')
            ->text->toBe('Привет')
            ->and($entry->contact->phone)->toBe('77011234567');
    });

    test('повтор обработки того же события не дублирует запись', function () {
        signedDereuEvent(['wamid' => 'wamid.inbound-2']);

        ProcessDereuWebhookEvent::dispatchSync(DereuWebhookEvent::sole());

        expect(ChannelMessage::count())->toBe(1);
    });
});

describe('статусы доставки', function () {
    function outboundEntry(string $uuid): ChannelMessage
    {
        return ChannelMessage::factory()->outbound()->create(['dereu_message_id' => $uuid]);
    }

    test('message_sent проставляет wamid, статус и время отправки', function () {
        $uuid = (string) Str::uuid();
        $entry = outboundEntry($uuid);

        signedDereuEvent([
            'event' => 'message_sent',
            'message_id' => $uuid,
            'wamid' => 'wamid.out-1',
            'from' => null, 'type' => null, 'payload' => [],
        ]);

        $entry->refresh();
        expect($entry->status)->toBe(ChannelMessageStatus::Sent)
            ->and($entry->wamid)->toBe('wamid.out-1')
            ->and($entry->sent_at)->not->toBeNull();
    });

    test('запоздавший «доставлено» после «прочитано» не откатывает статус', function () {
        $uuid = (string) Str::uuid();
        $entry = outboundEntry($uuid);

        signedDereuEvent(['event' => 'message_read', 'message_id' => $uuid, 'wamid' => 'w1', 'from' => null, 'type' => null, 'payload' => []]);
        signedDereuEvent(['event' => 'message_delivered', 'message_id' => $uuid, 'wamid' => 'w1', 'from' => null, 'type' => null, 'payload' => []]);

        $entry->refresh();
        expect($entry->status)->toBe(ChannelMessageStatus::Read)
            ->and($entry->read_at)->not->toBeNull()
            ->and($entry->delivered_at)->not->toBeNull();
    });

    test('message_failed фиксирует причину отказа', function () {
        $uuid = (string) Str::uuid();
        $entry = outboundEntry($uuid);

        signedDereuEvent([
            'event' => 'message_failed',
            'message_id' => $uuid,
            'reason' => 'Message failed to send because more than 24 hours have passed',
            'from' => null, 'type' => null, 'payload' => [],
        ]);

        $entry->refresh();
        expect($entry->status)->toBe(ChannelMessageStatus::Failed)
            ->and($entry->failure_reason)->toContain('24 hours');
    });

    /**
     * Строка журнала с запасным шаблоном: так выглядит сессионное
     * уведомление, отправленное в открытое по нашим часам окно.
     */
    function outboundEntryWithFallback(string $uuid, WhatsappTemplate $template): ChannelMessage
    {
        return ChannelMessage::factory()->outbound()->create([
            'dereu_message_id' => $uuid,
            'template_fallback' => [
                'whatsapp_template_id' => $template->id,
                'body_parameters' => ['Автокран 25т'],
                'button_payloads' => ['req:1:accept', 'req:1:decline'],
            ],
        ]);
    }

    test('message_failed из-за закрытого окна перепосылает уведомление платным шаблоном', function () {
        $uuid = (string) Str::uuid();
        $template = WhatsappTemplate::factory()->approved()->create();
        $entry = outboundEntryWithFallback($uuid, $template);

        test()->mock(DereuMessenger::class)
            ->shouldReceive('sendTemplate')->once()
            ->withArgs(fn (Contact $to, WhatsappTemplate $t, array $params, array $payloads): bool => $to->is($entry->contact)
                && $t->is($template)
                && $params === ['Автокран 25т']
                && $payloads === ['req:1:accept', 'req:1:decline']);

        signedDereuEvent([
            'event' => 'message_failed',
            'message_id' => $uuid,
            'reason' => 'Message failed to send because more than 24 hours have passed since the customer last replied to this number.',
            'from' => null, 'type' => null, 'payload' => [],
        ]);

        // Фолбэк одноразовый: израсходованный, он очищается — повторный
        // message_failed (передоставка события) не породит второй шаблон.
        expect($entry->refresh())
            ->status->toBe(ChannelMessageStatus::Failed)
            ->template_fallback->toBeNull();
    });

    test('повторный message_failed по тому же сообщению не перепосылает шаблон вторично', function () {
        $uuid = (string) Str::uuid();
        $template = WhatsappTemplate::factory()->approved()->create();
        outboundEntryWithFallback($uuid, $template);

        test()->mock(DereuMessenger::class)->shouldReceive('sendTemplate')->once();

        $reason = 'Message failed: re-engagement message (131047).';
        signedDereuEvent(['event' => 'message_failed', 'message_id' => $uuid, 'reason' => $reason, 'from' => null, 'type' => null, 'payload' => []]);
        signedDereuEvent(['event' => 'message_failed', 'message_id' => $uuid, 'reason' => $reason, 'from' => null, 'type' => null, 'payload' => []]);
    });

    test('message_failed по причине, не связанной с окном, шаблоном не перепосылается', function () {
        $uuid = (string) Str::uuid();
        $template = WhatsappTemplate::factory()->approved()->create();
        $entry = outboundEntryWithFallback($uuid, $template);

        test()->mock(DereuMessenger::class)->shouldNotReceive('sendTemplate');

        signedDereuEvent([
            'event' => 'message_failed',
            'message_id' => $uuid,
            'reason' => 'Invalid interactive button payload.',
            'from' => null, 'type' => null, 'payload' => [],
        ]);

        // Причина не «вне окна» — шаблон с тем же смыслом мог бы упасть так
        // же; фолбэк остаётся на строке для разбора оператором.
        expect($entry->refresh()->template_fallback)->not->toBeNull();
    });

    test('сбой самой переотправки не роняет обработку статуса', function () {
        $uuid = (string) Str::uuid();
        $template = WhatsappTemplate::factory()->approved()->create();
        outboundEntryWithFallback($uuid, $template);

        test()->mock(DereuMessenger::class)
            ->shouldReceive('sendTemplate')->once()->andThrow(new RuntimeException('Dereu 500'));

        signedDereuEvent([
            'event' => 'message_failed',
            'message_id' => $uuid,
            'reason' => 'more than 24 hours have passed',
            'from' => null, 'type' => null, 'payload' => [],
        ]);

        expect(DereuWebhookEvent::sole()->processed_at)->not->toBeNull();
    });

    test('неутверждённый к моменту отказа шаблон не перепосылается', function () {
        $uuid = (string) Str::uuid();
        $template = WhatsappTemplate::factory()->create();
        outboundEntryWithFallback($uuid, $template);

        test()->mock(DereuMessenger::class)->shouldNotReceive('sendTemplate');

        signedDereuEvent([
            'event' => 'message_failed',
            'message_id' => $uuid,
            'reason' => 'more than 24 hours have passed',
            'from' => null, 'type' => null, 'payload' => [],
        ]);
    });

    test('message_failed пишется в error-лог — мониторинг видит недоставленные ответы', function () {
        Log::spy();
        $uuid = (string) Str::uuid();
        outboundEntry($uuid);

        signedDereuEvent([
            'event' => 'message_failed',
            'message_id' => $uuid,
            'reason' => 'Message failed to send because more than 24 hours have passed',
            'from' => null, 'type' => null, 'payload' => [],
        ]);

        Log::shouldHaveReceived('error')->once();
    });

    test('статусное событие без строки журнала просто помечается обработанным', function () {
        signedDereuEvent(['event' => 'message_delivered', 'message_id' => (string) Str::uuid(), 'wamid' => 'w2', 'from' => null, 'type' => null, 'payload' => []]);

        expect(ChannelMessage::count())->toBe(0)
            ->and(DereuWebhookEvent::sole()->processed_at)->not->toBeNull();
    });
});
