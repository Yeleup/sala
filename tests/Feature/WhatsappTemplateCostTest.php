<?php

use App\Enums\AiCostStatus;
use App\Models\ChannelMessage;
use App\Models\Contact;
use App\Models\WhatsappTemplate;
use App\Services\DereuMessenger;
use App\Services\WhatsappCostEstimator;
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
    config()->set('whatsapp-pricing.categories.utility', 0.045);
    $contact = Contact::factory()->create();
    $template = WhatsappTemplate::factory()->approved()->create();

    app(DereuMessenger::class)->sendTemplate($contact, $template, ['Автокран 25т']);

    $message = ChannelMessage::sole();
    expect($message->whatsapp_template_id)->toBe($template->id)
        ->and($message->estimated_cost_usd)->toBe('0.045000')
        ->and($message->cost_status)->toBe(AiCostStatus::Estimated)
        ->and($message->pricing_snapshot)->toBe(['category' => 'utility', 'per_delivered_usd' => 0.045]);
});

test('шаблон без настроенного тарифа получает cost_status=unknown, а не ноль', function () {
    config()->set('whatsapp-pricing.categories.utility', null);
    $contact = Contact::factory()->create();
    $template = WhatsappTemplate::factory()->approved()->create();

    app(DereuMessenger::class)->sendTemplate($contact, $template);

    $message = ChannelMessage::sole();
    expect($message->whatsapp_template_id)->toBe($template->id)
        ->and($message->estimated_cost_usd)->toBeNull()
        ->and($message->cost_status)->toBe(AiCostStatus::Unknown)
        ->and($message->pricing_snapshot)->toBeNull();
});

test('сессионные сообщения не получают стоимости', function () {
    config()->set('whatsapp-pricing.categories.utility', 0.045);
    $contact = Contact::factory()->withOpenSessionWindow()->create();

    app(DereuMessenger::class)->sendText($contact, 'Привет!');

    $message = ChannelMessage::sole();
    expect($message->whatsapp_template_id)->toBeNull()
        ->and($message->estimated_cost_usd)->toBeNull()
        ->and($message->cost_status)->toBeNull()
        ->and($message->pricing_snapshot)->toBeNull();
});

test('оценщик без категории возвращает unknown', function () {
    $estimate = app(WhatsappCostEstimator::class)->estimate(null);

    expect($estimate['estimated_cost_usd'])->toBeNull()
        ->and($estimate['cost_status'])->toBe(AiCostStatus::Unknown)
        ->and($estimate['pricing_snapshot'])->toBeNull();
});
