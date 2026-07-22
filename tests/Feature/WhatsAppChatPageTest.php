<?php

use App\Enums\AiCostStatus;
use App\Enums\ChannelMessageStatus;
use App\Filament\Pages\WhatsAppChat;
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

test('тред показывает кнопки, элементы списка и ссылку исходящих интерактивов', function () {
    $contact = Contact::factory()->create();

    ChannelMessage::factory()->for($contact)->outbound()->create([
        'type' => 'interactive',
        'text' => 'Что вы хотите сделать?',
        'payload' => [
            'type' => 'button',
            'body' => ['text' => 'Что вы хотите сделать?'],
            'action' => ['buttons' => [
                ['type' => 'reply', 'reply' => ['id' => 'supplier', 'title' => 'Я поставщик']],
                ['type' => 'reply', 'reply' => ['id' => 'customer', 'title' => 'Я заказчик']],
            ]],
        ],
    ]);

    ChannelMessage::factory()->for($contact)->outbound()->create([
        'type' => 'interactive',
        'text' => 'Уточните, какое место ваше.',
        'payload' => [
            'type' => 'list',
            'body' => ['text' => 'Уточните, какое место ваше.'],
            'action' => [
                'button' => 'Выбрать место',
                'sections' => [['rows' => [
                    ['id' => 'listing_location:15531', 'title' => 'г.Астана'],
                    ['id' => 'listing_location:2602', 'title' => 'район Астана', 'description' => 'Актобе Г.А., Актюбинская область'],
                ]]],
            ],
        ],
    ]);

    ChannelMessage::factory()->for($contact)->outbound()->create([
        'type' => 'interactive',
        'text' => 'Откройте кабинет, чтобы исправить объявление.',
        'payload' => [
            'type' => 'cta_url',
            'body' => ['text' => 'Откройте кабинет, чтобы исправить объявление.'],
            'action' => ['name' => 'cta_url', 'parameters' => [
                'display_text' => 'Открыть кабинет',
                'url' => 'https://example.test/supplier/listings?signature=abc',
            ]],
        ],
    ]);

    Livewire::test(WhatsAppChat::class)
        ->call('selectContact', $contact->id)
        ->assertSee('Кнопки')
        ->assertSee('Я поставщик')
        ->assertSee('Я заказчик')
        ->assertSee('Список')
        ->assertSee('Выбрать место')
        ->assertSee('г.Астана')
        ->assertSee('район Астана')
        ->assertSee('Актобе Г.А., Актюбинская область')
        ->assertSee('Кнопка-ссылка')
        ->assertSee('Открыть кабинет')
        // Адрес виден текстом, но живой ссылкой не является: это персональная
        // подписанная ссылка контакта, переход из админки не предусмотрен.
        ->assertSee('https://example.test/supplier/listings?signature=abc')
        ->assertDontSeeHtml('href="https://example.test/supplier/listings?signature=abc"');
});

test('тред показывает, какую кнопку или пункт списка нажал контакт, и ошибки входящего payload', function () {
    $contact = Contact::factory()->create();

    ChannelMessage::factory()->for($contact)->create([
        'type' => 'interactive',
        'text' => 'Я заказчик',
        'payload' => ['type' => 'button_reply', 'button_reply' => ['id' => 'customer', 'title' => 'Я заказчик']],
    ]);
    ChannelMessage::factory()->for($contact)->create([
        'type' => 'interactive',
        'text' => 'г.Астана',
        'payload' => ['type' => 'list_reply', 'list_reply' => ['id' => 'listing_location:15531', 'title' => 'г.Астана']],
    ]);
    ChannelMessage::factory()->for($contact)->create([
        'type' => 'button',
        'text' => 'Согласиться',
        'payload' => ['text' => 'Согласиться', 'payload' => 'flow:token123:accept'],
    ]);
    ChannelMessage::factory()->for($contact)->create([
        'type' => 'interactive',
        'text' => null,
        'payload' => ['errors' => [['code' => 131000, 'title' => 'Something went wrong', 'error_data' => ['details' => 'Unsupported webhook payload']]]],
    ]);

    Livewire::test(WhatsAppChat::class)
        ->call('selectContact', $contact->id)
        ->assertSee('Нажата кнопка')
        ->assertSee('id: customer')
        ->assertSee('Выбран пункт списка')
        ->assertSee('id: listing_location:15531')
        ->assertSee('Кнопка шаблона')
        ->assertSee('id: flow:token123:accept')
        ->assertSee('Something went wrong: Unsupported webhook payload');
});

test('тред показывает текст шаблона с подставленными значениями и его кнопки', function () {
    $contact = Contact::factory()->create();

    $template = WhatsappTemplate::factory()->approved()->create([
        'name' => 'new_customer_request',
        'body' => 'По вашему объявлению «{{1}}» новая заявка от заказчика: «{{2}}». Готовы взять заказ?',
        'components' => [
            ['type' => 'BODY', 'text' => 'По вашему объявлению «{{1}}» новая заявка от заказчика: «{{2}}». Готовы взять заказ?'],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Согласиться'],
                ['type' => 'QUICK_REPLY', 'text' => 'Отказаться'],
            ]],
        ],
    ]);

    // Литеральный «{{2}}» в значении первого параметра остаётся как есть —
    // Meta подставляет плейсхолдеры одним проходом, без повторной замены.
    ChannelMessage::factory()->for($contact)->template($template)->create([
        'payload' => [
            'name' => 'new_customer_request',
            'language' => ['code' => 'ru'],
            'components' => [
                ['type' => 'body', 'parameters' => [
                    ['type' => 'text', 'text' => 'Самосвалы {{2}}'],
                    ['type' => 'text', 'text' => 'нужен самосвал, Астана'],
                ]],
                ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '0', 'parameters' => [['type' => 'payload', 'payload' => 'flow:tok:accept']]],
                ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '1', 'parameters' => [['type' => 'payload', 'payload' => 'flow:tok:decline']]],
            ],
        ],
    ]);

    // Шаблон, синхронизированный без кнопочных заголовков, — машинный payload кнопки виден как есть.
    $bare = WhatsappTemplate::factory()->approved()->create([
        'name' => 'bare_template',
        'body' => 'Объявление «{{1}}» скоро истечёт.',
        'components' => [['type' => 'BODY', 'text' => 'Объявление «{{1}}» скоро истечёт.']],
    ]);

    ChannelMessage::factory()->for($contact)->template($bare)->create([
        'payload' => [
            'name' => 'bare_template',
            'language' => ['code' => 'ru'],
            'components' => [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Кран 25 тонн']]],
                ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '0', 'parameters' => [['type' => 'payload', 'payload' => 'flow:tok2:renew']]],
            ],
        ],
    ]);

    Livewire::test(WhatsAppChat::class)
        ->call('selectContact', $contact->id)
        ->assertSee('Шаблон «new_customer_request»')
        ->assertSee('По вашему объявлению «Самосвалы {{2}}» новая заявка от заказчика: «нужен самосвал, Астана». Готовы взять заказ?')
        ->assertSee('Согласиться')
        ->assertSee('Отказаться')
        ->assertSee('Шаблон «bare_template»')
        ->assertSee('Объявление «Кран 25 тонн» скоро истечёт.')
        ->assertSee('flow:tok2:renew');
});

test('мусорный payload не роняет тред: нестроковые поля показываются пусто', function () {
    $contact = Contact::factory()->create();

    ChannelMessage::factory()->for($contact)->create([
        'type' => 'interactive',
        'text' => null,
        'payload' => ['errors' => [['title' => ['nested' => 'junk']], 'not-an-array']],
    ]);
    ChannelMessage::factory()->for($contact)->outbound()->create([
        'type' => 'interactive',
        'text' => 'Мусор в кнопках',
        'payload' => ['type' => 'button', 'action' => ['buttons' => ['garbage', ['reply' => 'тоже мусор'], ['reply' => ['title' => ['вложенный' => 'мусор']]]]]],
    ]);
    ChannelMessage::factory()->for($contact)->template()->create([
        'payload' => ['name' => 'x', 'components' => ['junk', ['type' => 'button', 'parameters' => 'junk'], ['type' => 'body', 'parameters' => ['junk', ['text' => ['мусор']]]]]],
    ]);

    Livewire::test(WhatsAppChat::class)
        ->call('selectContact', $contact->id)
        ->assertOk()
        ->assertSee('Мусор в кнопках');
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
