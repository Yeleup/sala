<?php

use App\Enums\AiCostStatus;
use App\Enums\AiOperationType;
use App\Enums\ChannelMessageStatus;
use App\Filament\Pages\AiUsageReport;
use App\Models\AiAttempt;
use App\Models\AiOperation;
use App\Models\ChannelMessage;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsappTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('отчёт показывает сводку, разрезы и топ контактов за период', function () {
    $contact = Contact::factory()->create(['phone' => '77019990000', 'profile_name' => 'Аскар']);
    $operation = AiOperation::factory()->create(['contact_id' => $contact->id]);

    AiAttempt::factory()->for($operation, 'operation')->create([
        'model' => 'gpt-5.4',
        'estimated_cost_usd' => '0.012345',
        'latency_ms' => 900,
        'input_tokens' => 1200,
        'output_tokens' => 300,
    ]);
    AiAttempt::factory()->for($operation, 'operation')->failed('Timeout')->create(['model' => 'gpt-5.4']);
    AiAttempt::factory()->for(AiOperation::factory()->transcription(), 'operation')->unknownCost()->create();

    Livewire::test(AiUsageReport::class)
        ->assertOk()
        ->assertSee('Извлечение объявления')
        ->assertSee('Транскрибация аудио')
        ->assertSee('gpt-5.4')
        ->assertSee('experimental-model')
        ->assertSee('без тарифа')
        ->assertSee('Аскар')
        ->assertSee('+77019990000');
});

test('суточные разрезы отчёта считаются в казахстанском времени, а не в UTC', function () {
    // 21:00 UTC 30 июля — это 02:00 31 июля по Алматы (UTC+5): ночные
    // вызовы и сообщения должны попадать в местные сутки, иначе суммы
    // размазываются между днями и не бьются с выгрузками Meta.
    $this->travelTo('2026-07-31 10:00:00');

    AiAttempt::factory()
        ->for(AiOperation::factory(), 'operation')
        ->create(['created_at' => '2026-07-30 21:00:00']);
    ChannelMessage::factory()->outbound()->create([
        'type' => 'template',
        'created_at' => '2026-07-30 21:00:00',
    ]);

    $page = Livewire::test(AiUsageReport::class)->instance();

    expect($page->byDay()->first()->day)->toBe('2026-07-31')
        ->and($page->whatsappByDay()->first()->day)->toBe('2026-07-31')
        ->and($page->messagesByDay()->first()->day)->toBe('2026-07-31');
});

test('векторизация для поиска видна в отчёте с моделью и стоимостью', function () {
    $operation = AiOperation::factory()->create(['operation' => AiOperationType::Embedding]);
    AiAttempt::factory()->for($operation, 'operation')->create([
        'model' => 'text-embedding-3-small',
        'estimated_cost_usd' => '0.000120',
        'input_tokens' => 6000,
        'output_tokens' => 0,
    ]);

    Livewire::test(AiUsageReport::class)
        ->assertOk()
        ->assertSee('Векторизация для поиска')
        ->assertSee('text-embedding-3-small');
});

test('период отсекает старые вызовы', function () {
    AiAttempt::factory()->create(['created_at' => now()->subDays(40), 'model' => 'ancient-model']);

    Livewire::test(AiUsageReport::class)
        ->assertOk()
        ->assertDontSee('ancient-model')
        ->set('days', 90)
        ->assertSee('ancient-model');
});

test('пустой период не ломает страницу', function () {
    Livewire::test(AiUsageReport::class)
        ->assertOk()
        ->assertSee('За период вызовов не было.')
        ->assertSee('За период шаблонов не отправлялось.')
        ->assertSee('За период сообщений не было.');
});

test('отчёт считает расходы на шаблоны только по доставленным и показывает счётчики сообщений', function () {
    $template = WhatsappTemplate::factory()->approved()->create(['name' => 'listing_renewal_report']);

    // Доставленный шаблон входит в сумму; в очереди — только в счётчик отправленных.
    ChannelMessage::factory()->template($template)->delivered()->create([
        'estimated_cost_usd' => '0.045000',
        'cost_status' => AiCostStatus::Estimated,
    ]);
    ChannelMessage::factory()->template($template)->create([
        'estimated_cost_usd' => '0.045000',
        'cost_status' => AiCostStatus::Estimated,
    ]);
    ChannelMessage::factory()->template($template)->delivered()->create([
        'estimated_cost_usd' => null,
        'cost_status' => AiCostStatus::Unknown,
    ]);
    ChannelMessage::factory()->template($template)->create([
        'created_at' => now()->subDays(40),
        'estimated_cost_usd' => '9.000000',
        'cost_status' => AiCostStatus::Estimated,
    ]);
    ChannelMessage::factory()->create(['text' => 'Входящее для счётчиков']);

    Livewire::test(AiUsageReport::class)
        ->assertOk()
        ->assertSee('$0.0450')
        ->assertDontSee('9.0000')
        ->assertSee('listing_renewal_report')
        ->assertSee('Утилитарный')
        ->assertSee('1 сообщение(й) без тарифа — не входят в сумму');
});

test('отчёт считает сессионные сообщения сверх бесплатного лимита и расход лимита текущего месяца', function () {
    $this->travelTo('2026-10-10 10:00:00');
    $template = WhatsappTemplate::factory()->approved()->create();

    ChannelMessage::factory()->template($template)->delivered()->create([
        'estimated_cost_usd' => '0.018000',
        'cost_status' => AiCostStatus::Estimated,
    ]);
    // Доставленные сессионные: одно уже сверх лимита, одно в его пределах.
    ChannelMessage::factory()->outbound()->delivered()->create([
        'estimated_cost_usd' => '0.018000',
        'cost_status' => AiCostStatus::Estimated,
    ]);
    ChannelMessage::factory()->outbound()->delivered()->create([
        'estimated_cost_usd' => '0.000000',
        'cost_status' => AiCostStatus::Estimated,
    ]);
    // Ещё не доставлено: лимит уже тратит, в деньги не входит.
    ChannelMessage::factory()->outbound()->create([
        'estimated_cost_usd' => '0.018000',
        'cost_status' => AiCostStatus::Estimated,
    ]);
    // Отклонённое Meta: ни в деньгах, ни в лимите.
    ChannelMessage::factory()->outbound()->create([
        'status' => ChannelMessageStatus::Failed,
        'estimated_cost_usd' => '0.018000',
        'cost_status' => AiCostStatus::Estimated,
    ]);
    // Оператор с телефона не стоит ничего и лимит не тратит.
    ChannelMessage::factory()->operator()->create();

    $page = Livewire::test(AiUsageReport::class)
        ->assertOk()
        ->assertSee('WhatsApp — итого $0.0360')
        ->assertSee('3 из 1 000')
        ->assertSee('$0.0180 за сообщение');

    expect($page->instance()->sessionSummary())->toBe([
        'delivered' => 2,
        'paid' => 1,
        'cost_usd' => '0.0180',
        'unknown_cost' => 0,
    ])
        ->and($page->instance()->whatsappByDay()->first())
        ->templates_sent->toBe(1)
        ->templates_delivered->toBe(1)
        ->templates_failed->toBe(0)
        ->session_delivered->toBe(2)
        ->cost->toBe('0.036000');
});

test('до 1 октября отчёт говорит, что сессионные бесплатны, и с какого числа это кончится', function () {
    $this->travelTo('2026-09-22 10:00:00');

    Livewire::test(AiUsageReport::class)
        ->assertOk()
        ->assertSee('Сессионные сейчас бесплатны; с 01.10.2026 — 1 000 в месяц бесплатно, дальше $0.0180 за сообщение');
});
