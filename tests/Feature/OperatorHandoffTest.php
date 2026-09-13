<?php

use App\Enums\ChannelDirection;
use App\Jobs\JournalDereuOperatorEcho;
use App\Jobs\ProcessDereuWebhookEvent;
use App\Models\BotScenario;
use App\Models\BotSession;
use App\Models\ChannelMessage;
use App\Models\Contact;
use App\Models\DereuCompany;
use App\Models\DereuWebhookEvent;
use App\Services\Bot\BotEngine;
use App\Services\Bot\InboundMessage;
use App\Services\Bot\ScenarioRunReplyHandler;
use App\Services\DereuMessenger;
use App\Services\DereuPlatformClient;
use App\Services\OperatorEchoJournal;
use App\Services\OperatorHandoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/**
 * Минимальный опубликованный сценарий: старт → меню. Больше для проверки
 * паузы не нужно — вопрос в том, отвечает бот или молчит.
 */
function handoffScenario(): BotScenario
{
    return BotScenario::factory()->published([
        'nodes' => [
            ['id' => 'start', 'type' => 'start'],
            ['id' => 'menu', 'type' => 'buttons', 'text' => 'Что вас интересует?', 'options' => [
                ['id' => 'rent', 'title' => 'Аренда'],
            ]],
            ['id' => 'rent_menu', 'type' => 'buttons', 'text' => 'Аренда. Предлагаете или ищете?', 'options' => [
                ['id' => 'rent_offer', 'title' => 'Предлагаю'],
            ]],
        ],
        'edges' => [
            ['from' => 'start', 'output' => 'continue', 'to' => 'menu'],
            ['from' => 'menu', 'output' => 'option:rent', 'to' => 'rent_menu'],
        ],
    ])->create();
}

function handoffSessionAt(BotScenario $scenario, Contact $contact): BotSession
{
    return BotSession::factory()->waitingAt('menu')->create([
        'contact_id' => $contact->id,
        'bot_scenario_id' => $scenario->id,
        'scenario_version' => $scenario->published_version,
    ]);
}

function handoffInboundEvent(Contact $contact): DereuWebhookEvent
{
    return DereuWebhookEvent::query()->create([
        'event' => 'message_received',
        'event_id' => (string) Str::ulid(),
        'dedupe_key' => 'wamid:'.Str::random(20),
        'company_id' => 'co_abc123',
        'phone_number_id' => '631370540065072',
        'wamid' => 'wamid.'.Str::random(20),
        'payload' => [
            'event' => 'message_received',
            'from' => $contact->phone,
            'type' => 'text',
            'text' => 'а объявление уже опубликовано?',
            'payload' => ['body' => 'а объявление уже опубликовано?'],
            'timestamp' => now()->timestamp,
        ],
    ]);
}

describe('когда пауза включается', function () {
    test('сообщение оператора в идущий разговор уводит бота в сторону', function () {
        // Инцидент 27 августа: оператор голосом вытаскивал человека из
        // диалога, а бот в те же минуты слал ему меню пять раз подряд.
        $contact = Contact::factory()->create(['phone' => '77774258186']);
        ChannelMessage::factory()->create(['contact_id' => $contact->id, 'created_at' => now()->subMinutes(3)]);

        (new JournalDereuOperatorEcho(operatorEchoEvent(['timestamp' => (string) now()->timestamp])))
            ->handle(app(OperatorEchoJournal::class), app(OperatorHandoff::class));

        expect(app(OperatorHandoff::class)->isActive($contact->fresh()))->toBeTrue();
    });

    test('холодная рассылка бота не глушит', function () {
        // Питч уходит десяткам номеров разом и заканчивается «напишите
        // „РЕМОНТ“» — а оформляет объявление как раз бот. Пауза после
        // рассылки означала бы, что ответившие не получат ничего.
        Contact::factory()->create(['phone' => '77774258186']);

        (new JournalDereuOperatorEcho(operatorEchoEvent(['timestamp' => (string) now()->timestamp])))
            ->handle(app(OperatorEchoJournal::class), app(OperatorHandoff::class));

        expect(app(OperatorHandoff::class)->isActive(Contact::sole()))->toBeFalse();
    });

    test('давний разговор оживить эхом нельзя', function () {
        $contact = Contact::factory()->create(['phone' => '77774258186']);
        ChannelMessage::factory()->create(['contact_id' => $contact->id, 'created_at' => now()->subDays(3)]);

        (new JournalDereuOperatorEcho(operatorEchoEvent(['timestamp' => (string) now()->timestamp])))
            ->handle(app(OperatorEchoJournal::class), app(OperatorHandoff::class));

        expect(app(OperatorHandoff::class)->isActive($contact->fresh()))->toBeFalse();
    });

    test('каждое следующее сообщение оператора отодвигает возврат бота', function () {
        $contact = Contact::factory()->create(['phone' => '77774258186']);
        ChannelMessage::factory()->create(['contact_id' => $contact->id, 'created_at' => now()->subMinutes(3)]);

        (new JournalDereuOperatorEcho(operatorEchoEvent(['timestamp' => (string) now()->timestamp])))
            ->handle(app(OperatorEchoJournal::class), app(OperatorHandoff::class));

        $first = $contact->fresh()->operator_handoff_until;

        $this->travel(10)->minutes();

        (new JournalDereuOperatorEcho(operatorEchoEvent(['timestamp' => (string) now()->timestamp])))
            ->handle(app(OperatorEchoJournal::class), app(OperatorHandoff::class));

        expect($contact->fresh()->operator_handoff_until->isAfter($first))->toBeTrue();
    });
});

describe('что бот перестаёт делать', function () {
    test('входящее в паузе журналируется и двигает окно, но «печатает…» не обещаем', function () {
        $contact = Contact::factory()->create(['phone' => '77774258186', 'last_inbound_at' => now()->subHour()]);
        app(OperatorHandoff::class)->start($contact);

        $this->mock(BotEngine::class, fn (MockInterface $mock) => $mock->shouldReceive('handle')->once());
        $this->mock(DereuPlatformClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('markMessageRead'));

        $event = handoffInboundEvent($contact);
        (new ProcessDereuWebhookEvent($event))->handle(app(BotEngine::class), app(DereuPlatformClient::class), app(OperatorHandoff::class));

        expect(ChannelMessage::where('direction', ChannelDirection::Inbound)->count())->toBe(1)
            ->and($contact->fresh()->last_inbound_at->isAfter(now()->subMinute()))->toBeTrue()
            ->and($event->fresh()->processed_at)->not->toBeNull();
    });

    test('на обычное сообщение в паузе бот не отвечает ничем', function () {
        $scenario = handoffScenario();
        $contact = Contact::factory()->create(['phone' => '77774258186']);
        handoffSessionAt($scenario, $contact);
        app(OperatorHandoff::class)->start($contact);

        $messenger = $this->mock(DereuMessenger::class);
        $messenger->shouldNotReceive('sendText');
        $messenger->shouldNotReceive('sendButtons');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'а объявление уже опубликовано?'));
    });

    test('нажатие кнопки своего же уведомления бот отвечает и в паузе', function () {
        // Событие помечается обработанным и второй раз не проигрывается:
        // проглоченное [Да, актуально] — это открытый запуск и объявление,
        // ушедшее в автоархив по молчанию, пока оператор писал тому же
        // человеку о другом.
        $contact = Contact::factory()->create(['phone' => '77774258186']);
        app(OperatorHandoff::class)->start($contact);

        $this->mock(ScenarioRunReplyHandler::class, fn (MockInterface $mock) => $mock->shouldReceive('handle')->once()->andReturnTrue());

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Да, актуально', replyId: 'flow:tok:yes'));
    });

    test('кнопка уведомления отвечается, но разговор оператору оставляет', function () {
        // Граница исключения: [Да, актуально] закрывает вопрос про
        // объявление, а не говорит «хочу дальше с ботом». Отвечаем и
        // молчим дальше — разговор всё ещё ведёт человек.
        $contact = Contact::factory()->create(['phone' => '77774258186']);
        app(OperatorHandoff::class)->start($contact);

        $this->mock(ScenarioRunReplyHandler::class, fn (MockInterface $mock) => $mock->shouldReceive('handle')->once()->andReturnTrue());

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Да, актуально', replyId: 'flow:tok:yes'));

        expect(app(OperatorHandoff::class)->isActive($contact->fresh()))->toBeTrue();
    });

    test('нажатие кнопки собственного меню бот отвечает и в паузе', function () {
        // Инцидент 12 сентября, контакт 369: оператор прислал голосовое —
        // и три нажатия «Ремонт спецтехники» подряд ушли в тишину. Человек
        // видел живые кнопки, жал их и не получал ничего, а потом написал
        // «Чет шыкпай жатырго» — и это тоже пропало.
        $scenario = handoffScenario();
        $contact = Contact::factory()->create(['phone' => '77474630083']);
        handoffSessionAt($scenario, $contact);
        app(OperatorHandoff::class)->start($contact);

        $this->mock(DereuMessenger::class)
            ->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Аренда. Предлагаете или ищете?');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Аренда', replyId: 'rent'));

        expect(BotSession::sole()->current_node_id)->toBe('rent_menu');
    });

    test('ответом на свою кнопку пауза снимается — дальше бот ведёт разговор сам', function () {
        // Иначе починка была бы наполовину: шаг с кнопками бот прошёл бы,
        // а на первом же вопросе, где ждут слова, человек снова упёрся бы
        // в ту же тишину.
        $scenario = handoffScenario();
        $contact = Contact::factory()->create(['phone' => '77474630083']);
        handoffSessionAt($scenario, $contact);
        app(OperatorHandoff::class)->start($contact);

        $this->mock(DereuMessenger::class)->shouldReceive('sendButtons')->once();

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Аренда', replyId: 'rent'));

        expect($contact->fresh()->operator_handoff_until)->toBeNull();
    });

    test('срок вышел — бот снова отвечает', function () {
        config()->set('services.dereu.external_id', 'org_наша');
        DereuCompany::factory()->create(['external_id' => 'org_наша', 'dereu_company_id' => 'co_abc123']);

        $contact = Contact::factory()->create(['phone' => '77774258186']);
        app(OperatorHandoff::class)->start($contact);

        $this->travel(OperatorHandoff::HANDOFF_MINUTES + 1)->minutes();

        $this->mock(BotEngine::class, fn (MockInterface $mock) => $mock->shouldReceive('handle')->once());
        $this->mock(DereuPlatformClient::class, fn (MockInterface $mock) => $mock->shouldReceive('markMessageRead')->once());

        (new ProcessDereuWebhookEvent(handoffInboundEvent($contact)))
            ->handle(app(BotEngine::class), app(DereuPlatformClient::class), app(OperatorHandoff::class));
    });
});

describe('возврат бота', function () {
    test('снятие режима закрывает диалог, чтобы бот не очнулся на старом вопросе', function () {
        $contact = Contact::factory()->create();
        $session = BotSession::factory()->waitingAt('main_menu')->create(['contact_id' => $contact->id]);

        $handoff = app(OperatorHandoff::class);
        $handoff->start($contact);
        $handoff->end($contact->fresh());

        expect($contact->fresh())
            ->operator_handoff_until->toBeNull()
            ->and($session->fresh())
            ->current_node_id->toBeNull()
            ->last_dialog_ended_at->not->toBeNull();
    });

    test('снятие того, чего нет, ничего не трогает', function () {
        $contact = Contact::factory()->create();
        $session = BotSession::factory()->waitingAt('main_menu')->create(['contact_id' => $contact->id]);

        app(OperatorHandoff::class)->end($contact);

        expect($session->fresh()->current_node_id)->toBe('main_menu');
    });

    test('истёкшая пауза закрывает диалог так же, как кнопка', function () {
        // Иначе бот вернулся бы стоя на «Что вас интересует?» — вопросе,
        // на который человек час назад ответил живому человеку.
        $contact = Contact::factory()->create();
        $session = BotSession::factory()->waitingAt('main_menu')->create(['contact_id' => $contact->id]);

        $handoff = app(OperatorHandoff::class);
        $handoff->start($contact);

        $this->travel(OperatorHandoff::HANDOFF_MINUTES + 1)->minutes();
        $handoff->releaseExpired($contact->fresh());

        expect($contact->fresh()->operator_handoff_until)->toBeNull()
            ->and($session->fresh())
            ->current_node_id->toBeNull()
            ->last_dialog_ended_at->not->toBeNull();
    });

    test('пока пауза идёт, снимать её по сроку нечего', function () {
        $contact = Contact::factory()->create();
        $session = BotSession::factory()->waitingAt('main_menu')->create(['contact_id' => $contact->id]);

        $handoff = app(OperatorHandoff::class);
        $handoff->start($contact);
        $handoff->releaseExpired($contact->fresh());

        expect($contact->fresh()->operator_handoff_until)->not->toBeNull()
            ->and($session->fresh()->current_node_id)->toBe('main_menu');
    });

    test('первое нажатие после истёкшей паузы идёт по кнопке, а не в начало диалога', function () {
        // Контакт 247: пауза истекла, он нажал «Аренда спецтехники» — и
        // снятие паузы обнулило диалог, поэтому в ответ пришло всё то же
        // главное меню. Правильно нажатие прошло только со второго раза,
        // через одиннадцать секунд.
        $scenario = handoffScenario();
        $contact = Contact::factory()->create(['phone' => '77474258186']);
        handoffSessionAt($scenario, $contact);
        app(OperatorHandoff::class)->start($contact);

        $this->travel(OperatorHandoff::HANDOFF_MINUTES + 1)->minutes();

        $this->mock(DereuMessenger::class)
            ->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Аренда. Предлагаете или ищете?');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Аренда', replyId: 'rent'));

        expect(BotSession::sole()->current_node_id)->toBe('rent_menu');
    });

    test('первое же сообщение после срока снимает паузу', function () {
        $scenario = handoffScenario();
        $contact = Contact::factory()->create(['phone' => '77774258186']);
        handoffSessionAt($scenario, $contact);
        app(OperatorHandoff::class)->start($contact);

        $this->travel(OperatorHandoff::HANDOFF_MINUTES + 1)->minutes();

        $messenger = $this->mock(DereuMessenger::class);
        $messenger->shouldReceive('sendText')->zeroOrMoreTimes();
        $messenger->shouldReceive('sendButtons')->zeroOrMoreTimes();

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'здравствуйте'));

        expect($contact->fresh()->operator_handoff_until)->toBeNull();
    });
});
