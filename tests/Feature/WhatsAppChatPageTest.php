<?php

use App\Enums\AiCostStatus;
use App\Enums\ChannelMessageStatus;
use App\Filament\Pages\WhatsAppChat;
use App\Models\AiAttempt;
use App\Models\AiOperation;
use App\Models\ChannelMessage;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('чат показывает диалоги по последнему сообщению и ищет по телефону и имени', function () {
    $earlier = Contact::factory()->create(['phone' => '77010000001', 'profile_name' => 'Аскар']);
    $later = Contact::factory()->create(['phone' => '77010000002', 'display_name' => 'Берик']);
    $silent = Contact::factory()->create(['phone' => '77010000003', 'profile_name' => 'Молчун']);

    ChannelMessage::factory()->for($earlier)->create(['text' => 'Первое сообщение', 'created_at' => now()->subHour()]);
    ChannelMessage::factory()->for($later)->create(['text' => 'Второе сообщение', 'created_at' => now()]);

    Livewire::test(WhatsAppChat::class)
        ->assertOk()
        ->assertSeeInOrder(['Берик', 'Аскар'])
        ->assertDontSee('Молчун')
        ->set('search', '77010000001')
        ->assertSee('Аскар')
        ->assertDontSee('Берик')
        ->set('search', 'Берик')
        ->assertSee('Берик')
        ->assertDontSee('Аскар');
});

test('тред показывает входящие и исходящие со статусами доставки и причиной ошибки', function () {
    $contact = Contact::factory()->create();

    ChannelMessage::factory()->for($contact)->create(['text' => 'Хочу сдать экскаватор']);
    ChannelMessage::factory()->for($contact)->outbound()->create([
        'text' => 'Какая цена?',
        'status' => ChannelMessageStatus::Read,
        'read_at' => now(),
    ]);
    ChannelMessage::factory()->for($contact)->outbound()->create([
        'text' => 'Недоставленное',
        'status' => ChannelMessageStatus::Failed,
        'failure_reason' => 'Meta rejected: invalid recipient',
    ]);

    Livewire::test(WhatsAppChat::class)
        ->call('selectContact', $contact->id)
        ->assertSee('Хочу сдать экскаватор')
        ->assertSee('Какая цена?')
        ->assertSee('Не доставлено: Meta rejected: invalid recipient');
});

test('AI-панель раскрывает операции и попытки входящего сообщения', function () {
    $contact = Contact::factory()->create();
    $message = ChannelMessage::factory()->for($contact)->create(['text' => 'Сдаю кран 25 тонн']);

    $operation = AiOperation::factory()->create([
        'contact_id' => $contact->id,
        'channel_message_id' => $message->id,
    ]);
    AiAttempt::factory()->for($operation, 'operation')->create([
        'model' => 'gpt-5.4',
        'prompt' => 'Извлеки поля объявления из текста поставщика',
        'response' => '{"clarifying_question":"Уточните город"}',
        'estimated_cost_usd' => '0.012000',
    ]);

    Livewire::test(WhatsAppChat::class)
        ->call('selectContact', $contact->id)
        ->assertSee('Извлечение объявления')
        ->assertSee('gpt-5.4')
        ->assertSee('Извлеки поля объявления из текста поставщика')
        ->assertSee('Уточните город');
});

test('кнопка подгрузки показывает более ранние сообщения', function () {
    $contact = Contact::factory()->create();

    ChannelMessage::factory()->for($contact)->create(['text' => 'Самое раннее сообщение', 'created_at' => now()->subDay()]);
    ChannelMessage::factory()->for($contact)->count(55)->create(['text' => 'Обычное сообщение']);

    Livewire::test(WhatsAppChat::class)
        ->call('selectContact', $contact->id)
        ->assertSee('Показать более ранние')
        ->assertDontSee('Самое раннее сообщение')
        ->call('loadOlder')
        ->assertSee('Самое раннее сообщение');
});

test('шапка треда показывает расходы AI и шаблонов и счётчики сообщений', function () {
    $contact = Contact::factory()->create();
    ChannelMessage::factory()->for($contact)->create(['text' => 'Входящее']);

    $operation = AiOperation::factory()->create(['contact_id' => $contact->id]);
    AiAttempt::factory()->for($operation, 'operation')->create(['estimated_cost_usd' => '0.020000']);

    // Доставленный шаблон входит в сумму, отправленный в очередь — нет.
    ChannelMessage::factory()->for($contact)->template()->delivered()->create([
        'estimated_cost_usd' => '0.045000',
        'cost_status' => AiCostStatus::Estimated,
    ]);
    ChannelMessage::factory()->for($contact)->template()->create([
        'estimated_cost_usd' => '0.045000',
        'cost_status' => AiCostStatus::Estimated,
    ]);
    ChannelMessage::factory()->for($contact)->template()->delivered()->create([
        'estimated_cost_usd' => null,
        'cost_status' => AiCostStatus::Unknown,
    ]);

    Livewire::test(WhatsAppChat::class)
        ->call('selectContact', $contact->id)
        ->assertSee('AI: $0.0200')
        ->assertSee('Шаблоны: $0.0450')
        ->assertSee('без тарифа: 1')
        ->assertSee('вх: 1')
        ->assertSee('исх: 3')
        ->assertSee('шаблонов: 3');
});
