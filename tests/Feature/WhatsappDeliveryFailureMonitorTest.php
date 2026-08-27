<?php

use App\Enums\ChannelMessageStatus;
use App\Models\ChannelMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Единственной реакцией на смерть канала были пассивные счётчики failed на
 * страницах чата и отчёта — их никто не обязан открывать: инцидент июля
 * 2026 (527 из 528 шаблонов убиты кодом 131042) заметили с опозданием.
 * Скользящее окно раз в пять минут — активный сигнал админам в панель.
 */
beforeEach(function () {
    config()->set('whatsapp-monitoring.window_minutes', 30);
    config()->set('whatsapp-monitoring.min_failed', 3);
    config()->set('whatsapp-monitoring.failed_share', 0.25);
    config()->set('whatsapp-monitoring.cooldown_minutes', 60);

    $this->admins = User::factory()->count(2)->create();
});

test('всплеск недоставленных исходящих будит админов в панели', function () {
    ChannelMessage::factory()->outbound()->count(3)->create([
        'status' => ChannelMessageStatus::Failed,
        'failure_reason' => 'meta error 131026: Message undeliverable — Message Undeliverable.',
    ]);
    ChannelMessage::factory()->outbound()->delivered()->create();

    $this->artisan('whatsapp:monitor-delivery-failures')->assertSuccessful();

    foreach ($this->admins as $admin) {
        $notifications = $admin->refresh()->notifications;
        expect($notifications)->toHaveCount(1)
            ->and($notifications->first()->data['title'])->toBe('WhatsApp: всплеск недоставленных сообщений')
            ->and($notifications->first()->data['body'])->toContain('3 из 4');
    }
});

test('единичный отказ в потоке доставленных не тревожит', function () {
    ChannelMessage::factory()->outbound()->create([
        'status' => ChannelMessageStatus::Failed,
        'failure_reason' => 'meta error 131026: Message undeliverable — Message Undeliverable.',
    ]);
    ChannelMessage::factory()->outbound()->delivered()->count(9)->create();

    $this->artisan('whatsapp:monitor-delivery-failures')->assertSuccessful();

    expect($this->admins->first()->refresh()->notifications)->toHaveCount(0);
});

test('отказы ниже порога доли не тревожат даже при достаточной выборке', function () {
    // 3 из 30 — это 10% при min_failed = 3: выборка порог проходит, доля
    // нет. Единственный случай, разделяющий два гейта: без него порог доли
    // можно вырезать из команды целиком, и сьют останется зелёным.
    ChannelMessage::factory()->outbound()->count(3)->create([
        'status' => ChannelMessageStatus::Failed,
        'failure_reason' => 'meta error 131026: Message undeliverable — Message Undeliverable.',
    ]);
    ChannelMessage::factory()->outbound()->delivered()->count(27)->create();

    $this->artisan('whatsapp:monitor-delivery-failures')->assertSuccessful();

    expect($this->admins->first()->refresh()->notifications)->toHaveCount(0);
});

test('доля ровно на пороге уже тревожит', function () {
    // 3 из 12 — ровно 25%: граница включительная, иначе настроенный порог
    // означал бы «строго больше» и читался бы не так, как записан.
    ChannelMessage::factory()->outbound()->count(3)->create([
        'status' => ChannelMessageStatus::Failed,
        'failure_reason' => 'meta error 131026: Message undeliverable — Message Undeliverable.',
    ]);
    ChannelMessage::factory()->outbound()->delivered()->count(9)->create();

    $this->artisan('whatsapp:monitor-delivery-failures')->assertSuccessful();

    expect($this->admins->first()->refresh()->notifications)->toHaveCount(1);
});

test('единичные отказы не всплеск даже при стопроцентной доле', function () {
    // 2 из 2 — это 100%, но выборка меньше минимума: ночью, когда бот шлёт
    // по одному сообщению в час, каждый случайный отказ давал бы тревогу.
    ChannelMessage::factory()->outbound()->count(2)->create([
        'status' => ChannelMessageStatus::Failed,
        'failure_reason' => 'meta error 131026: Message undeliverable — Message Undeliverable.',
    ]);

    $this->artisan('whatsapp:monitor-delivery-failures')->assertSuccessful();

    expect($this->admins->first()->refresh()->notifications)->toHaveCount(0);
});

test('входящие сообщения не разбавляют долю отказов', function () {
    ChannelMessage::factory()->outbound()->count(3)->create([
        'status' => ChannelMessageStatus::Failed,
        'failure_reason' => 'meta error 131026: Message undeliverable — Message Undeliverable.',
    ]);
    ChannelMessage::factory()->count(20)->create();

    $this->artisan('whatsapp:monitor-delivery-failures')->assertSuccessful();

    expect($this->admins->first()->refresh()->notifications)->toHaveCount(1);
});

test('сообщения старше окна не считаются всплеском', function () {
    ChannelMessage::factory()->outbound()->count(5)->create([
        'status' => ChannelMessageStatus::Failed,
        'failure_reason' => 'meta error 131026: Message undeliverable — Message Undeliverable.',
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);

    $this->artisan('whatsapp:monitor-delivery-failures')->assertSuccessful();

    expect($this->admins->first()->refresh()->notifications)->toHaveCount(0);
});

test('аккаунт-уровневый код тревожит отдельно даже без всплеска', function () {
    // 131042 значит «не уходит ничего никому» — ждать, пока отказы наберут
    // долю, нельзя: единственный платный шаблон в тихий час уже сигнал.
    ChannelMessage::factory()->outbound()->create([
        'status' => ChannelMessageStatus::Failed,
        'failure_reason' => 'meta error 131042: Business eligibility payment issue — Message failed to send because your WhatsApp Business account currency is not configured.',
    ]);
    ChannelMessage::factory()->outbound()->delivered()->count(9)->create();

    $this->artisan('whatsapp:monitor-delivery-failures')->assertSuccessful();

    foreach ($this->admins as $admin) {
        $notifications = $admin->refresh()->notifications;
        expect($notifications)->toHaveCount(1)
            ->and($notifications->first()->data['title'])->toBe('WhatsApp: сбой на уровне аккаунта')
            ->and($notifications->first()->data['body'])->toContain('131042')
            ->and($notifications->first()->data['body'])->toContain('биллинг');
    }
});

test('поздний вердикт по давнему сообщению всё равно даёт аккаунт-сигнал', function () {
    // Асинхронный отказ Meta прилетает позже создания записи: окно для
    // аккаунт-кодов считается по моменту вердикта, не по моменту отправки.
    ChannelMessage::factory()->outbound()->create([
        'status' => ChannelMessageStatus::Failed,
        'failure_reason' => 'HTTP request returned status code 400: {"error":{"code":131048,"title":"Spam rate limit hit"}}',
        'created_at' => now()->subHours(3),
        'updated_at' => now()->subMinutes(2),
    ]);

    $this->artisan('whatsapp:monitor-delivery-failures')->assertSuccessful();

    $notifications = $this->admins->first()->refresh()->notifications;
    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->data['title'])->toBe('WhatsApp: сбой на уровне аккаунта');
});

test('отказ без причины не даёт аккаунт-сигнала и не роняет проверку', function () {
    // reason у message_failed бывает null — это штатный вид отказа.
    ChannelMessage::factory()->outbound()->count(3)->create([
        'status' => ChannelMessageStatus::Failed,
        'failure_reason' => null,
    ]);

    $this->artisan('whatsapp:monitor-delivery-failures')->assertSuccessful();

    $notifications = $this->admins->first()->refresh()->notifications;
    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->data['title'])->toBe('WhatsApp: всплеск недоставленных сообщений');
});

test('повторная проверка в период охлаждения не дублирует тревогу', function () {
    ChannelMessage::factory()->outbound()->count(3)->create([
        'status' => ChannelMessageStatus::Failed,
        'failure_reason' => 'meta error 131042: Business eligibility payment issue.',
    ]);

    $this->artisan('whatsapp:monitor-delivery-failures')->assertSuccessful();
    $this->artisan('whatsapp:monitor-delivery-failures')->assertSuccessful();

    // Оба сигнала (всплеск и аккаунт-код) отправлены по одному разу.
    expect($this->admins->first()->refresh()->notifications)->toHaveCount(2);
});
