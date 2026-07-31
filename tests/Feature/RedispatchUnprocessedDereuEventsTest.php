<?php

use App\Jobs\ApplyDereuDeliveryStatus;
use App\Jobs\ApplyDereuTemplateStatus;
use App\Jobs\ApplyDereuWabaDisconnect;
use App\Jobs\ProcessDereuWebhookEvent;
use App\Models\DereuWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('a stale unprocessed inbound event is dispatched again', function () {
    Queue::fake();
    $event = DereuWebhookEvent::factory()->create(['created_at' => now()->subMinutes(20)]);

    $this->artisan('dereu:redispatch-unprocessed-events')->assertSuccessful();

    Queue::assertPushed(
        ProcessDereuWebhookEvent::class,
        fn (ProcessDereuWebhookEvent $job): bool => $job->event->is($event),
    );
});

test('fresh, processed and expired events are left alone', function () {
    Queue::fake();

    // Свежее событие ещё может ждать блокировку своей живой джобы (retryUntil
    // = 10 минут); обработанное — закрыто; старше суток — сессионное окно
    // WhatsApp давно ушло, и ответ на него запутал бы диалог, а не спас его.
    DereuWebhookEvent::factory()->create(['created_at' => now()->subMinutes(5)]);
    DereuWebhookEvent::factory()->create(['created_at' => now()->subMinutes(20), 'processed_at' => now()]);
    DereuWebhookEvent::factory()->create(['created_at' => now()->subHours(25)]);

    $this->artisan('dereu:redispatch-unprocessed-events')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('every event type is redispatched to its own job', function () {
    Queue::fake();

    DereuWebhookEvent::factory()->create(['event' => 'message_failed', 'created_at' => now()->subMinutes(20)]);
    DereuWebhookEvent::factory()->create(['event' => 'template_status_update', 'created_at' => now()->subMinutes(20)]);
    DereuWebhookEvent::factory()->create(['event' => 'waba_disconnected', 'created_at' => now()->subMinutes(20)]);

    $this->artisan('dereu:redispatch-unprocessed-events')->assertSuccessful();

    Queue::assertPushed(ApplyDereuDeliveryStatus::class, 1);
    Queue::assertPushed(ApplyDereuTemplateStatus::class, 1);
    Queue::assertPushed(ApplyDereuWabaDisconnect::class, 1);
});

test('an event type no job handles is not redispatched', function () {
    Queue::fake();
    DereuWebhookEvent::factory()->create(['event' => 'billing_ping', 'created_at' => now()->subMinutes(20)]);

    $this->artisan('dereu:redispatch-unprocessed-events')->assertSuccessful();

    Queue::assertNothingPushed();
});
