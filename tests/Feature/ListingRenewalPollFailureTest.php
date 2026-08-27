<?php

use App\Enums\BotScenarioTrigger;
use App\Enums\ListingStatus;
use App\Enums\WhatsappTemplateStatus;
use App\Models\BotScenario;
use App\Models\ChannelMessage;
use App\Models\Contact;
use App\Models\Listing;
use App\Models\ListingRenewalBatch;
use App\Models\ScenarioRun;
use App\Models\WhatsappTemplate;
use App\Services\Bot\NotificationReplyHandler;
use App\Services\DereuMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.dereu.webhook_secret', 'whsec_test');
    config()->set('services.dereu.external_id', 'org_test');
    // Джобы вебхука должны исполниться в самом тесте (контейнерное окружение
    // перебивает phpunit.xml, поэтому очередь переключается здесь).
    config()->set('queue.default', 'sync');
});

/**
 * Асинхронный отказ Meta по сообщению, которое Dereu уже принял: ровно так
 * выглядел биллинговый инцидент 131042, когда 527 из 528 шаблонов опроса
 * не дошли, а объявления наутро молча уехали в архив.
 */
function failedRenewalDelivery(string $dereuMessageId, string $reason = 'Message failed with error code 131042: billing issue.'): void
{
    $payload = [
        'event' => 'message_failed',
        'event_id' => (string) Str::ulid(),
        'company_id' => 'co_abc123',
        'phone_number_id' => '1234567890',
        'message_id' => $dereuMessageId,
        'reason' => $reason,
        'from' => null,
        'type' => null,
        'payload' => [],
        'timestamp' => 1718000000,
    ];

    test()->postJson(route('webhooks.dereu'), $payload, [
        'X-Dereu-Signature' => 'sha256='.hash_hmac('sha256', json_encode($payload), 'whsec_test'),
    ])->assertNoContent();
}

/** Объявление, опрошенное в свои последние сутки: отметка стоит, срок ещё идёт. */
function polledListing(?Contact $supplier = null): Listing
{
    return Listing::factory()
        ->published()
        ->for($supplier ?? Contact::factory()->create(), 'supplier')
        ->create([
            'expires_at' => now()->addHours(12),
            'renewal_requested_at' => now(),
        ]);
}

/**
 * Строка журнала одиночного опроса — интерактивное сообщение с кнопками
 * [Да, актуально]/[Нет, в архив], как его отправляет ListingRenewalNotifier.
 */
function interactivePollEntry(Listing $listing, string $uuid): ChannelMessage
{
    return ChannelMessage::factory()->outbound()->create([
        'contact_id' => $listing->contact_id,
        'type' => 'interactive',
        'dereu_message_id' => $uuid,
        'payload' => [
            'type' => 'button',
            'body' => ['text' => 'Оно ещё актуально?'],
            'action' => ['buttons' => [
                ['type' => 'reply', 'reply' => ['id' => NotificationReplyHandler::renewalYesId($listing), 'title' => 'Да, актуально']],
                ['type' => 'reply', 'reply' => ['id' => NotificationReplyHandler::renewalNoId($listing), 'title' => 'Нет, в архив']],
            ]],
        ],
    ]);
}

/** Строка журнала пачечного опроса, ушедшего платным шаблоном. */
function templateBatchPollEntry(ListingRenewalBatch $batch, string $uuid): ChannelMessage
{
    $button = fn (int $index, string $payload): array => [
        'type' => 'button',
        'sub_type' => 'quick_reply',
        'index' => (string) $index,
        'parameters' => [['type' => 'payload', 'payload' => $payload]],
    ];

    return ChannelMessage::factory()->outbound()->create([
        'contact_id' => $batch->contact_id,
        'type' => 'template',
        'dereu_message_id' => $uuid,
        'payload' => [
            'name' => 'several_listings_renewal',
            'language' => ['code' => 'ru'],
            'components' => [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Кран']]],
                $button(0, NotificationReplyHandler::batchRenewAllId($batch)),
                $button(1, NotificationReplyHandler::batchPickId($batch)),
                $button(2, NotificationReplyHandler::batchArchiveAllId($batch)),
            ],
        ],
    ]);
}

describe('недоставленный опрос актуальности', function () {
    test('асинхронный отказ по одиночному опросу снимает отметку — следующий прогон спросит снова', function () {
        $listing = polledListing();
        $uuid = (string) Str::uuid();
        interactivePollEntry($listing, $uuid);

        failedRenewalDelivery($uuid);

        expect($listing->refresh())
            ->renewal_requested_at->toBeNull()
            ->status->toBe(ListingStatus::Published);
    });

    test('отказ по пачечному шаблону возвращает в очередь опроса все объявления пачки', function () {
        $supplier = Contact::factory()->create();
        $listings = collect([polledListing($supplier), polledListing($supplier)]);
        $batch = ListingRenewalBatch::factory()->create(['contact_id' => $supplier->id]);
        $batch->listings()->attach($listings->pluck('id')->all());
        $uuid = (string) Str::uuid();
        templateBatchPollEntry($batch, $uuid);

        failedRenewalDelivery($uuid);

        expect($listings->every(fn (Listing $listing): bool => $listing->refresh()->renewal_requested_at === null))->toBeTrue();
    });

    test('отказ по кнопкам сценарного опроса находит объявление через subject запуска', function () {
        $listing = polledListing();
        $scenario = BotScenario::factory()->trigger(BotScenarioTrigger::ListingExpiring)->published()->create();
        $run = ScenarioRun::factory()->create([
            'bot_scenario_id' => $scenario->id,
            'contact_id' => $listing->contact_id,
        ]);
        $run->subject()->associate($listing)->save();
        $uuid = (string) Str::uuid();
        ChannelMessage::factory()->outbound()->create([
            'contact_id' => $listing->contact_id,
            'type' => 'interactive',
            'dereu_message_id' => $uuid,
            'payload' => [
                'type' => 'button',
                'body' => ['text' => 'Оно ещё актуально?'],
                'action' => ['buttons' => [
                    ['type' => 'reply', 'reply' => ['id' => "flow:{$run->token}:yes", 'title' => 'Да, актуально']],
                ]],
            ],
        ]);

        failedRenewalDelivery($uuid);

        expect($listing->refresh()->renewal_requested_at)->toBeNull();
    });

    test('отказ кнопок сценария не про продление отметку не трогает', function () {
        $listing = polledListing();
        $scenario = BotScenario::factory()->trigger(BotScenarioTrigger::NewCustomerRequest)->published()->create();
        $run = ScenarioRun::factory()->create([
            'bot_scenario_id' => $scenario->id,
            'contact_id' => $listing->contact_id,
        ]);
        $run->subject()->associate($listing)->save();
        $uuid = (string) Str::uuid();
        ChannelMessage::factory()->outbound()->create([
            'contact_id' => $listing->contact_id,
            'type' => 'interactive',
            'dereu_message_id' => $uuid,
            'payload' => [
                'type' => 'button',
                'body' => ['text' => 'Возьмёте заказ?'],
                'action' => ['buttons' => [
                    ['type' => 'reply', 'reply' => ['id' => "flow:{$run->token}:accept", 'title' => 'Согласиться']],
                ]],
            ],
        ]);

        failedRenewalDelivery($uuid);

        expect($listing->refresh()->renewal_requested_at)->not->toBeNull();
    });

    test('отказ сообщения без кнопок опроса ничего не сбрасывает', function () {
        $listing = polledListing();
        $uuid = (string) Str::uuid();
        ChannelMessage::factory()->outbound()->create([
            'contact_id' => $listing->contact_id,
            'dereu_message_id' => $uuid,
        ]);

        failedRenewalDelivery($uuid);

        expect($listing->refresh()->renewal_requested_at)->not->toBeNull();
    });

    test('после удачной переотправки шаблоном-фолбэком отметка остаётся — вопрос ещё в пути', function () {
        $listing = polledListing();
        $template = WhatsappTemplate::factory()->approved()->create();
        $uuid = (string) Str::uuid();
        $entry = interactivePollEntry($listing, $uuid);
        $entry->update(['template_fallback' => [
            'whatsapp_template_id' => $template->id,
            'body_parameters' => ['Кран'],
            'button_payloads' => [NotificationReplyHandler::renewalYesId($listing), NotificationReplyHandler::renewalNoId($listing)],
        ]]);

        test()->mock(DereuMessenger::class)->shouldReceive('sendTemplate')->once();

        failedRenewalDelivery($uuid, 'Message failed to send because more than 24 hours have passed since the customer last replied to this number.');

        expect($listing->refresh()->renewal_requested_at)->not->toBeNull();
    });

    test('неутверждённый к моменту отказа шаблон-фолбэк отметку не спасает', function () {
        // План Б есть, но шаблон к этому моменту отклонён Meta: вопрос
        // никуда не уходит, и объявление обязано вернуться в очередь на
        // опрос — иначе оно уедет в архив неспрошенным.
        $listing = polledListing();
        $template = WhatsappTemplate::factory()->create(['status' => WhatsappTemplateStatus::Rejected]);
        $uuid = (string) Str::uuid();
        interactivePollEntry($listing, $uuid)->update(['template_fallback' => [
            'whatsapp_template_id' => $template->id,
            'body_parameters' => ['Кран'],
            'button_payloads' => [NotificationReplyHandler::renewalYesId($listing)],
        ]]);

        failedRenewalDelivery($uuid, 'Message failed to send because more than 24 hours have passed since the customer last replied to this number.');

        expect($listing->refresh()->renewal_requested_at)->toBeNull();
    });

    test('сорвавшаяся переотправка шаблоном отметку не спасает', function () {
        // План Б выбран, но отправка упала (Dereu ответил ошибкой): попытка
        // одна, и вопрос остался недоставленным.
        $listing = polledListing();
        $template = WhatsappTemplate::factory()->approved()->create();
        $uuid = (string) Str::uuid();
        interactivePollEntry($listing, $uuid)->update(['template_fallback' => [
            'whatsapp_template_id' => $template->id,
            'body_parameters' => ['Кран'],
            'button_payloads' => [NotificationReplyHandler::renewalYesId($listing)],
        ]]);

        test()->mock(DereuMessenger::class)
            ->shouldReceive('sendTemplate')
            ->once()
            ->andThrow(new RuntimeException('Dereu недоступен'));

        failedRenewalDelivery($uuid, 'Message failed to send because more than 24 hours have passed since the customer last replied to this number.');

        expect($listing->refresh()->renewal_requested_at)->toBeNull();
    });

    test('повторная обработка того же отказа не снимает отметку с уже переотправленного опроса', function () {
        // Джобу переигрывают (ретрай или пятиминутный свипер необработанных
        // событий). Клейм плана Б стирает фолбэк, поэтому без отметки о
        // состоявшейся переотправке второй прогон читал бы «плана Б не было»
        // и вернул объявление в очередь — поставщик получил бы тот же
        // вопрос второй раз, и второй платный шаблон.
        $listing = polledListing();
        $template = WhatsappTemplate::factory()->approved()->create();
        $uuid = (string) Str::uuid();
        $entry = interactivePollEntry($listing, $uuid);
        $entry->update([
            'template_fallback' => null,
            'template_fallback_resent_at' => now(),
        ]);

        test()->mock(DereuMessenger::class)->shouldReceive('sendTemplate')->never();

        failedRenewalDelivery($uuid, 'Message failed to send because more than 24 hours have passed since the customer last replied to this number.');

        expect($listing->refresh()->renewal_requested_at)->not->toBeNull();
    });

    test('запоздалый отказ по опросу прошлого цикла свежую отметку не трогает', function () {
        // Отказ передоставили спустя месяц: сообщение принадлежит прошлому
        // 30-дневному циклу, а отметка уже относится к новому опросу.
        $listing = polledListing();
        $uuid = (string) Str::uuid();
        interactivePollEntry($listing, $uuid)->forceFill(['created_at' => now()->subDays(30)])->save();

        failedRenewalDelivery($uuid);

        expect($listing->refresh()->renewal_requested_at)->not->toBeNull();
    });
});
