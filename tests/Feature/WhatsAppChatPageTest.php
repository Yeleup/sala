<?php

use App\Enums\AiCostStatus;
use App\Enums\AiOperationType;
use App\Enums\ChannelMessageStatus;
use App\Filament\Pages\WhatsAppChat;
use App\Models\AiAttempt;
use App\Models\AiOperation;
use App\Models\ChannelMessage;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsappTemplate;
use App\Services\OperatorHandoff;
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

test('времена сообщений показываются в казахстанском времени', function () {
    $this->travelTo('2026-07-31 12:00:00');
    $contact = Contact::factory()->create();

    // 20:30 UTC 30 июля = 01:30 31 июля по Алматы: оператор, ищущий
    // сообщение по времени из жалобы клиента, должен видеть местное время
    // и местную дату разделителя, а не UTC на 5 часов позади.
    ChannelMessage::factory()->for($contact)->create([
        'text' => 'Ночное сообщение',
        'created_at' => '2026-07-30 20:30:00',
    ]);

    Livewire::test(WhatsAppChat::class)
        ->call('selectContact', $contact->id)
        ->assertSee('01:30')
        ->assertSee('31.07.2026')
        ->assertDontSee('20:30')
        ->assertDontSee('30.07.2026');
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

test('причина недоставки показана по-русски, а строка Meta остаётся в подсказке', function () {
    $contact = Contact::factory()->create();

    ChannelMessage::factory()->for($contact)->outbound()->create([
        'text' => 'Новая заявка',
        'status' => ChannelMessageStatus::Failed,
        'failure_reason' => 'meta error 131026: Message undeliverable — Message Undeliverable.',
    ]);

    // Причину Meta присылает не всегда: пузырь без неё показывал одну
    // красную галочку и ничего больше — это читалось как сбой вёрстки.
    ChannelMessage::factory()->for($contact)->outbound()->create([
        'text' => 'Молча упавшее',
        'status' => ChannelMessageStatus::Failed,
        'failure_reason' => null,
    ]);

    Livewire::test(WhatsAppChat::class)
        ->call('selectContact', $contact->id)
        ->assertSee('Не доставлено: на этом номере нет рабочего WhatsApp — свяжитесь звонком и проверьте номер')
        ->assertSee('Не доставлено: Meta не назвала причину')
        ->assertSee('meta error 131026: Message undeliverable');
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

test('шапка треда показывает расходы AI, шаблонов и сессионных и счётчики сообщений', function () {
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
    // Сессионное сверх бесплатного лимита стоит денег и помечено в ленте;
    // бесплатное в пределах лимита — ни в сумме, ни меткой.
    ChannelMessage::factory()->for($contact)->outbound()->delivered()->create([
        'text' => 'Платный ответ бота',
        'estimated_cost_usd' => '0.018000',
        'cost_status' => AiCostStatus::Estimated,
        'pricing_snapshot' => ['category' => 'service', 'per_delivered_usd' => 0.018, 'rate_card' => '2026-10-01', 'free_tier' => 1000, 'position_in_month' => 1001],
    ]);
    ChannelMessage::factory()->for($contact)->outbound()->delivered()->create([
        'text' => 'Бесплатный ответ бота',
        'estimated_cost_usd' => '0.000000',
        'cost_status' => AiCostStatus::Estimated,
    ]);
    // Не доставленное — в очереди или отклонённое Meta — не списывается:
    // ни в сумме, ни ценой в ленте.
    ChannelMessage::factory()->for($contact)->outbound()->create([
        'text' => 'Ответ бота в очереди',
        'estimated_cost_usd' => '0.020000',
        'cost_status' => AiCostStatus::Estimated,
        'pricing_snapshot' => ['category' => 'service', 'per_delivered_usd' => 0.02, 'rate_card' => '2026-10-01', 'free_tier' => 1000, 'position_in_month' => 1002],
    ]);
    ChannelMessage::factory()->for($contact)->outbound()->create([
        'text' => 'Отклонённый ответ бота',
        'status' => ChannelMessageStatus::Failed,
        'estimated_cost_usd' => '0.030000',
        'cost_status' => AiCostStatus::Estimated,
    ]);
    // Без тарифа: два доставленных сессионных против одного шаблона выше.
    ChannelMessage::factory()->for($contact)->outbound()->delivered()->count(2)->create([
        'estimated_cost_usd' => null,
        'cost_status' => AiCostStatus::Unknown,
    ]);

    $page = Livewire::test(WhatsAppChat::class)
        ->call('selectContact', $contact->id)
        ->assertSee('AI: $0.0200')
        ->assertSee('Шаблоны: $0.0450')
        ->assertSee('Сессионные: $0.0180')
        ->assertSee('Бесплатный ответ бота')
        ->assertSee('$0.0180 · сверх лимита')
        ->assertSee('$0.0200 · сверх лимита')
        ->assertDontSee('$0.0000 · сверх лимита')
        ->assertDontSee('$0.0300')
        ->assertSee('без тарифа: 1')
        ->assertSee('без тарифа: 2')
        ->assertSee('вх: 1')
        ->assertSee('бот: 9')
        ->assertSee('шаблонов: 3');

    expect($page->instance()->contactTotals())
        ->toMatchArray(['session_cost' => '0.0180', 'session_unknown' => 2, 'template_unknown' => 1]);
});

test('в ленте видны три стороны, и у сообщения оператора нет галочек доставки', function () {
    // Инцидент 27 августа: оператор голосом вытаскивал человека из
    // диалога, а в админке была видна только половина переписки — бот.
    $contact = Contact::factory()->create();
    ChannelMessage::factory()->for($contact)->create(['text' => 'Так отправилось или нет мой объявление', 'created_at' => now()->subMinutes(3)]);
    ChannelMessage::factory()->for($contact)->outbound()->create(['text' => 'Что вас интересует?', 'created_at' => now()->subMinutes(2)]);
    ChannelMessage::factory()->for($contact)->operator()->create(['text' => 'Ваше объявление загружено! Спасибо', 'created_at' => now()->subMinute()]);

    $page = Livewire::test(WhatsAppChat::class)
        ->call('selectContact', $contact->id)
        ->assertSee('Так отправилось или нет мой объявление')
        ->assertSee('Что вас интересует?')
        ->assertSee('Ваше объявление загружено! Спасибо')
        ->assertSee('Оператор')
        ->assertSee('оператор: 1')
        // Своя заливка — иначе пузырь оператора неотличим от пузыря бота.
        ->assertSeeHtml('wa-bubble is-out is-op');

    // Галочек у сообщения из приложения быть не должно: статусов доставки
    // на него не приходит, и одинокая ✓ читалась бы как «не доставлено».
    $ticks = substr_count($page->html(), 'class="wa-ticks');

    expect($ticks)->toBe(1);
});

test('плашка о паузе и возврат бота', function () {
    $contact = Contact::factory()->create();
    ChannelMessage::factory()->for($contact)->create();
    app(OperatorHandoff::class)->start($contact);

    Livewire::test(WhatsAppChat::class)
        ->call('selectContact', $contact->id)
        ->assertSee('Оператор ведёт диалог')
        ->call('returnToBot');

    expect($contact->fresh()->operator_handoff_until)->toBeNull();
});

test('без паузы плашки нет', function () {
    $contact = Contact::factory()->create();
    ChannelMessage::factory()->for($contact)->create();

    Livewire::test(WhatsAppChat::class)
        ->call('selectContact', $contact->id)
        ->assertDontSee('Оператор ведёт диалог');
});

test('панель ИИ объясняет решение навигатора словами, а не только сырым JSON', function () {
    // Кейс 169: на «👍» модель ответила верно — «не понял», уверенно, — но
    // по сырому ответу нельзя было понять, почему за этим последовало
    // полное приветствие. Теперь решение подписано.
    $contact = Contact::factory()->create();
    $inbound = ChannelMessage::factory()->for($contact)->create(['text' => '👍']);

    $operation = AiOperation::factory()->create([
        'contact_id' => $contact->id,
        'channel_message_id' => $inbound->id,
        'operation' => AiOperationType::MenuRouting,
    ]);
    AiAttempt::factory()->for($operation, 'operation')->create([
        'response' => json_encode(['intent' => 'acknowledgement', 'option' => 'none', 'confidence' => 'high']),
    ]);

    Livewire::test(WhatsAppChat::class)
        ->call('selectContact', $contact->id)
        ->assertSee('Навигатор: подтверждение, бот промолчал, уверенно');
});

test('нечитаемый ответ модели панель не ломает', function (?string $response) {
    $contact = Contact::factory()->create();
    $inbound = ChannelMessage::factory()->for($contact)->create();

    $operation = AiOperation::factory()->create([
        'contact_id' => $contact->id,
        'channel_message_id' => $inbound->id,
        'operation' => AiOperationType::MenuRouting,
    ]);
    AiAttempt::factory()->for($operation, 'operation')->create(['response' => $response]);

    Livewire::test(WhatsAppChat::class)
        ->call('selectContact', $contact->id)
        ->assertOk()
        ->assertDontSee('Навигатор:');
})->with([
    'ответа нет' => [null],
    'не JSON' => ['провайдер вернул текст'],
    'незнакомое намерение' => ['{"intent":"что-то новое","option":"none","confidence":"high"}'],
]);
