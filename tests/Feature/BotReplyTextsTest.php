<?php

use App\Enums\BotReplyKey;
use App\Filament\Pages\BotReplyTextsPage;
use App\Models\BotReplyText;
use App\Models\BotScenario;
use App\Models\BotSession;
use App\Models\Contact;
use App\Models\ScenarioRun;
use App\Models\User;
use App\Services\Bot\BotEngine;
use App\Services\Bot\BotReplyTexts;
use App\Services\Bot\InboundMessage;
use App\Services\Bot\ScenarioRunReplyHandler;
use App\Services\DereuMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // env контейнера перекрывает CACHE_STORE из phpunit.xml — фиксируем
    // array-стор явно (как в WhatsAppSettingsTest) и чистим кэш набора:
    // rememberForever течёт между тестами одного процесса.
    config()->set('cache.default', 'array');
    app(BotReplyTexts::class)->flush();
});

afterEach(function () {
    app(BotReplyTexts::class)->flush();
});

/**
 * Старт → меню с одной кнопкой: минимальный опубликованный главный сценарий.
 */
function replyTextsMenuScenario(): BotScenario
{
    return BotScenario::factory()->published([
        'nodes' => [
            ['id' => 'start', 'type' => 'start'],
            ['id' => 'menu', 'type' => 'buttons', 'text' => 'Кто вы?', 'options' => [
                ['id' => 'supplier', 'title' => 'Поставщик'],
            ]],
            ['id' => 'branch', 'type' => 'text', 'text' => 'Ветка поставщика'],
        ],
        'edges' => [
            ['from' => 'start', 'output' => 'continue', 'to' => 'menu'],
            ['from' => 'menu', 'output' => 'option:supplier', 'to' => 'branch'],
        ],
    ])->create();
}

describe('читатель BotReplyTexts', function () {
    test('без переопределений возвращает стандартный текст', function () {
        expect(app(BotReplyTexts::class)->get(BotReplyKey::StaleButton))
            ->toBe(BotReplyKey::StaleButton->default());
    });

    test('строка в таблице переопределяет стандартный текст', function () {
        BotReplyText::query()->create(['key' => 'stale_button', 'text' => 'Свой текст про кнопку']);

        expect(app(BotReplyTexts::class)->get(BotReplyKey::StaleButton))->toBe('Свой текст про кнопку');
    });

    test('строка из пробелов равнозначна отсутствию переопределения', function () {
        BotReplyText::query()->create(['key' => 'stale_button', 'text' => '   ']);

        expect(app(BotReplyTexts::class)->get(BotReplyKey::StaleButton))
            ->toBe(BotReplyKey::StaleButton->default());
    });

    test('набор кэшируется и сбрасывается flush-ом', function () {
        $texts = app(BotReplyTexts::class);

        expect($texts->get(BotReplyKey::StaleButton))->toBe(BotReplyKey::StaleButton->default());

        // Запись мимо страницы не видна, пока кэш не сброшен.
        BotReplyText::query()->create(['key' => 'stale_button', 'text' => 'Свой текст']);

        expect($texts->get(BotReplyKey::StaleButton))->toBe(BotReplyKey::StaleButton->default());

        $texts->flush();

        expect($texts->get(BotReplyKey::StaleButton))->toBe('Свой текст');
    });
});

describe('страница «Ответы бота»', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create());
    });

    test('гость перенаправляется на вход', function () {
        auth()->logout();

        $this->get(BotReplyTextsPage::getUrl())->assertRedirect();
    });

    test('страница показывает все ответы с их подписями', function () {
        $response = $this->get(BotReplyTextsPage::getUrl())->assertSuccessful();

        foreach (BotReplyKey::cases() as $key) {
            $response->assertSee($key->label());
        }
    });

    test('сохранение пишет переопределение и сбрасывает кэш', function () {
        app(BotReplyTexts::class)->get(BotReplyKey::StaleButton); // прогрев кэша

        Livewire::test(BotReplyTextsPage::class)
            ->fillForm(['stale_button' => 'Свой текст про кнопку'])
            ->call('save')
            ->assertNotified('Ответы бота сохранены');

        expect(BotReplyText::query()->where('key', 'stale_button')->sole()->text)->toBe('Свой текст про кнопку')
            ->and(app(BotReplyTexts::class)->get(BotReplyKey::StaleButton))->toBe('Свой текст про кнопку');
    });

    test('очищенное поле удаляет переопределение — возвращается стандартный текст', function () {
        BotReplyText::query()->create(['key' => 'stale_button', 'text' => 'Свой текст']);

        Livewire::test(BotReplyTextsPage::class)
            ->fillForm(['stale_button' => ''])
            ->call('save');

        expect(BotReplyText::query()->where('key', 'stale_button')->exists())->toBeFalse()
            ->and(app(BotReplyTexts::class)->get(BotReplyKey::StaleButton))
            ->toBe(BotReplyKey::StaleButton->default());
    });
});

describe('переопределённые тексты в рантайме', function () {
    test('устаревшая кнопка получает свой текст вместо стандартного', function () {
        $scenario = replyTextsMenuScenario();
        $contact = Contact::factory()->create();
        BotSession::factory()->waitingAt('menu')->create([
            'contact_id' => $contact->id,
            'bot_scenario_id' => $scenario->id,
            'scenario_version' => $scenario->published_version,
        ]);

        BotReplyText::query()->create(['key' => 'stale_button', 'text' => 'Свой текст про кнопку']);

        $messenger = test()->mock(DereuMessenger::class);
        $messenger->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Свой текст про кнопку');
        $messenger->shouldReceive('sendButtons')->once();

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'x', replyId: 'ghost_button'));
    });

    test('нераспознанное нажатие получает свой текст вместо стандартного', function () {
        $scenario = replyTextsMenuScenario();
        $contact = Contact::factory()->create();
        $session = BotSession::factory()->waitingAt('menu')->create([
            'contact_id' => $contact->id,
            'bot_scenario_id' => $scenario->id,
            'scenario_version' => $scenario->published_version,
        ]);

        BotReplyText::query()->create(['key' => 'unrecognized_press', 'text' => 'Свой текст про сбой']);

        // Ровно одно сообщение: без повтора шага и без перезапуска диалога.
        $messenger = test()->mock(DereuMessenger::class);
        $messenger->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Свой текст про сбой');

        app(BotEngine::class)->handle($contact, new InboundMessage(unrecognizedPress: true));

        expect($session->fresh()->current_node_id)->toBe('menu');
    });

    test('клик по кнопке завершённого запуска получает свой текст «вопрос закрыт»', function () {
        $contact = Contact::factory()->withOpenSessionWindow()->create();
        $run = ScenarioRun::factory()->completed()->create(['contact_id' => $contact->id]);

        BotReplyText::query()->create(['key' => 'run_decision_final', 'text' => 'Решение уже принято, ничего не меняем.']);

        $messenger = test()->mock(DereuMessenger::class);
        $messenger->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Решение уже принято, ничего не меняем.');

        $handled = app(ScenarioRunReplyHandler::class)->handle(
            $contact,
            new InboundMessage(replyId: "flow:{$run->token}:accept"),
        );

        expect($handled)->toBeTrue();
    });
});
