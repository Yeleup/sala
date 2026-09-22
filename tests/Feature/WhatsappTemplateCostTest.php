<?php

use App\Enums\AiCostStatus;
use App\Enums\ChannelMessageStatus;
use App\Enums\WhatsappTemplateCategory;
use App\Jobs\ApplyDereuDeliveryStatus;
use App\Models\ChannelMessage;
use App\Models\Contact;
use App\Models\DereuWebhookEvent;
use App\Models\WhatsappTemplate;
use App\Services\DereuMessenger;
use App\Services\WhatsappCostEstimator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.dereu.external_id', 'org_test');
    config()->set('services.dereu.base_url', 'https://api.dereu.test/api/v1');

    Http::preventStrayRequests();
    Http::fake([
        'api.dereu.test/*' => fn () => Http::response(['id' => (string) Str::uuid(), 'status' => 'queued'], 202),
    ]);

    connectedDereuCompany();
});

test('отправка шаблона фиксирует снимок тарифа и оценку стоимости на сообщении', function () {
    $this->travelTo('2026-10-05 10:00:00');
    $contact = Contact::factory()->create();
    $template = WhatsappTemplate::factory()->approved()->create();

    app(DereuMessenger::class)->sendTemplate($contact, $template, ['Автокран 25т']);

    $message = ChannelMessage::sole();
    expect($message->whatsapp_template_id)->toBe($template->id)
        ->and($message->estimated_cost_usd)->toBe('0.018000')
        ->and($message->cost_status)->toBe(AiCostStatus::Estimated)
        ->and($message->pricing_snapshot)->toBe(['category' => 'utility', 'per_delivered_usd' => 0.018, 'rate_card' => '2026-10-01']);
});

test('прайс-лист переключается в полночь по часовому поясу WhatsApp-аккаунта, а не по UTC', function () {
    // 18:59 UTC 30 сентября — ещё 23:59 по Алматы (UTC+5), старый прайс
    // региона «Other»; 19:00 UTC — уже полночь 1 октября, отдельная строка
    // Казахстана с utility втрое дороже.
    $estimator = app(WhatsappCostEstimator::class);
    $before = $estimator->estimate(WhatsappTemplateCategory::Utility, CarbonImmutable::parse('2026-09-30 18:59:59', 'UTC'));
    $after = $estimator->estimate(WhatsappTemplateCategory::Utility, CarbonImmutable::parse('2026-09-30 19:00:00', 'UTC'));

    expect($before['estimated_cost_usd'])->toBe('0.007700')
        ->and($before['pricing_snapshot']['rate_card'])->toBe('2026-07-01')
        ->and($after['estimated_cost_usd'])->toBe('0.018000')
        ->and($after['pricing_snapshot']['rate_card'])->toBe('2026-10-01');
});

test('шаблон без настроенного тарифа получает cost_status=unknown, а не ноль', function () {
    $this->travelTo('2026-10-05 10:00:00');
    config()->set('whatsapp-pricing.rate_cards.2026-10-01.categories.utility', null);
    $contact = Contact::factory()->create();
    $template = WhatsappTemplate::factory()->approved()->create();

    app(DereuMessenger::class)->sendTemplate($contact, $template);

    $message = ChannelMessage::sole();
    expect($message->whatsapp_template_id)->toBe($template->id)
        ->and($message->estimated_cost_usd)->toBeNull()
        ->and($message->cost_status)->toBe(AiCostStatus::Unknown)
        ->and($message->pricing_snapshot)->toBeNull();
});

test('до первого прайс-листа стоимость неизвестна, а не ноль', function () {
    $estimator = app(WhatsappCostEstimator::class);
    $moment = CarbonImmutable::parse('2026-06-15 10:00:00', 'UTC');

    expect($estimator->estimate(WhatsappTemplateCategory::Utility, $moment)['cost_status'])->toBe(AiCostStatus::Unknown)
        ->and($estimator->estimateSession($moment)['cost_status'])->toBe(AiCostStatus::Unknown);
});

test('до 1 октября сессионное сообщение бесплатно, но оценку получает явным нулём', function () {
    $this->travelTo('2026-09-22 10:00:00');
    $contact = Contact::factory()->withOpenSessionWindow()->create();

    app(DereuMessenger::class)->sendText($contact, 'Привет!');

    $message = ChannelMessage::sole();
    expect($message->whatsapp_template_id)->toBeNull()
        ->and($message->estimated_cost_usd)->toBe('0.000000')
        ->and($message->cost_status)->toBe(AiCostStatus::Estimated)
        // JSON хранит 0.0 как 0 — сравнение по значению, не по типу.
        ->and($message->pricing_snapshot)->toEqual(['category' => 'service', 'per_delivered_usd' => 0, 'rate_card' => '2026-07-01']);
});

test('с 1 октября сессионные бесплатны в пределах месячного лимита и платные сверх него', function () {
    $this->travelTo('2026-10-05 10:00:00');
    config()->set('whatsapp-pricing.rate_cards.2026-10-01.service_free_tier', 2);
    $contact = Contact::factory()->withOpenSessionWindow()->create();
    $messenger = app(DereuMessenger::class);

    $messenger->sendText($contact, 'Первое');
    $messenger->sendButtons($contact, 'Второе', [['id' => 'menu', 'title' => 'В меню']]);
    $messenger->sendText($contact, 'Третье');

    $messages = ChannelMessage::query()->orderBy('id')->get();
    expect($messages->pluck('estimated_cost_usd')->all())->toBe(['0.000000', '0.000000', '0.018000'])
        ->and($messages->pluck('pricing_snapshot.position_in_month')->all())->toBe([1, 2, 3])
        ->and($messages->last()->pricing_snapshot)->toBe([
            'category' => 'service',
            'per_delivered_usd' => 0.018,
            'rate_card' => '2026-10-01',
            'free_tier' => 2,
            'position_in_month' => 3,
        ]);
});

test('бесплатный лимит тратят только непроваленные сессионные сообщения бота текущего месяца по часовому поясу аккаунта', function () {
    // 19:30 UTC 31 октября — это 00:30 1 ноября по Алматы: лимит ноября
    // начался полчаса назад.
    $this->travelTo('2026-10-31 19:30:00');
    config()->set('whatsapp-pricing.rate_cards.2026-10-01.service_free_tier', 1);
    $contact = Contact::factory()->withOpenSessionWindow()->create();

    // 23:00 31 октября по Алматы — октябрьский лимит, ноябрь не трогает.
    ChannelMessage::factory()->for($contact)->outbound()->delivered()->create(['created_at' => '2026-10-31 18:00:00']);
    // Ноябрьские, но лимит не расходуют: отказ, сообщение оператора, шаблон.
    ChannelMessage::factory()->for($contact)->outbound()->create(['status' => ChannelMessageStatus::Failed, 'created_at' => '2026-10-31 19:10:00']);
    ChannelMessage::factory()->for($contact)->operator()->create(['created_at' => '2026-10-31 19:15:00']);
    ChannelMessage::factory()->for($contact)->template()->delivered()->create(['created_at' => '2026-10-31 19:20:00']);

    $messenger = app(DereuMessenger::class);
    $messenger->sendText($contact, 'Первое в ноябре');
    $messenger->sendText($contact, 'Второе в ноябре');

    $sent = ChannelMessage::query()->whereIn('text', ['Первое в ноябре', 'Второе в ноябре'])->orderBy('id')->get();
    expect($sent->pluck('estimated_cost_usd')->all())->toBe(['0.000000', '0.018000'])
        ->and($sent->pluck('pricing_snapshot.position_in_month')->all())->toBe([1, 2]);
});

test('сессионное сообщение без настроенной ставки service получает cost_status=unknown, а не ноль', function () {
    $this->travelTo('2026-10-05 10:00:00');
    config()->set('whatsapp-pricing.rate_cards.2026-10-01.categories.service', null);
    $contact = Contact::factory()->withOpenSessionWindow()->create();

    app(DereuMessenger::class)->sendText($contact, 'Привет!');

    $message = ChannelMessage::sole();
    expect($message->cost_status)->toBe(AiCostStatus::Unknown)
        ->and($message->estimated_cost_usd)->toBeNull()
        ->and($message->pricing_snapshot)->toBeNull();
});

test('недоставленное сообщение из бесплатного лимита отдаёт слот первому платному после него — один раз', function () {
    // Meta считает лимит по доставленным: первое сообщение считалось как
    // будто дойдёт, из-за него второе ушло «сверх лимита». Отказ пришёл
    // позже — второе на самом деле бесплатное, третье по-прежнему платное.
    $this->travelTo('2026-10-05 10:00:00');
    config()->set('whatsapp-pricing.rate_cards.2026-10-01.service_free_tier', 1);
    $contact = Contact::factory()->withOpenSessionWindow()->create();
    $messenger = app(DereuMessenger::class);

    $messenger->sendText($contact, 'Первое');
    $messenger->sendText($contact, 'Второе');
    $messenger->sendText($contact, 'Третье');
    [$first, $second, $third] = ChannelMessage::query()->orderBy('id')->get()->all();

    $failure = fn (): DereuWebhookEvent => DereuWebhookEvent::factory()->create([
        'event' => 'message_failed',
        'payload' => ['event' => 'message_failed', 'message_id' => $first->dereu_message_id, 'reason' => 'meta error 131026: Message undeliverable'],
    ]);
    ApplyDereuDeliveryStatus::dispatchSync($failure());
    // Повторный отказ того же сообщения второй слот не освобождает.
    ApplyDereuDeliveryStatus::dispatchSync($failure());

    expect($first->fresh()->status)->toBe(ChannelMessageStatus::Failed)
        ->and($second->fresh()->estimated_cost_usd)->toBe('0.000000')
        ->and($second->fresh()->pricing_snapshot['freed_by_failed_message_id'])->toBe($first->id)
        ->and($third->fresh()->estimated_cost_usd)->toBe('0.018000');
});

test('отказ платного сообщения ничьих слотов не освобождает', function () {
    $this->travelTo('2026-10-05 10:00:00');
    config()->set('whatsapp-pricing.rate_cards.2026-10-01.service_free_tier', 1);
    $contact = Contact::factory()->withOpenSessionWindow()->create();
    $messenger = app(DereuMessenger::class);

    $messenger->sendText($contact, 'Первое');
    $messenger->sendText($contact, 'Второе');
    $messenger->sendText($contact, 'Третье');
    [, $second, $third] = ChannelMessage::query()->orderBy('id')->get()->all();

    ApplyDereuDeliveryStatus::dispatchSync(DereuWebhookEvent::factory()->create([
        'event' => 'message_failed',
        'payload' => ['event' => 'message_failed', 'message_id' => $second->dereu_message_id, 'reason' => 'meta error 131026: Message undeliverable'],
    ]));

    expect($third->fresh()->estimated_cost_usd)->toBe('0.018000');
});

test('оценщик без категории возвращает unknown', function () {
    $estimate = app(WhatsappCostEstimator::class)->estimate(null);

    expect($estimate['estimated_cost_usd'])->toBeNull()
        ->and($estimate['cost_status'])->toBe(AiCostStatus::Unknown)
        ->and($estimate['pricing_snapshot'])->toBeNull();
});
