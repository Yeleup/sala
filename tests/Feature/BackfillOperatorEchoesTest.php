<?php

use App\Enums\ChannelMessageAuthor;
use App\Models\ChannelMessage;
use App\Models\Contact;
use App\Models\DereuCompany;
use App\Services\OperatorHandoff;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('накопленные сообщения оператора попадают в переписки своим временем', function () {
    $contact = Contact::factory()->create(['phone' => '77774258186']);
    operatorEchoEvent();

    $this->artisan('dereu:backfill-operator-echoes')->assertSuccessful();

    expect(ChannelMessage::sole())
        ->contact_id->toBe($contact->id)
        ->author->toBe(ChannelMessageAuthor::Operator)
        ->and(ChannelMessage::sole()->created_at->timestamp)->toBe(1788866846);
});

test('повторный прогон ничего не удваивает', function () {
    Contact::factory()->create(['phone' => '77774258186']);
    $event = operatorEchoEvent();

    $this->artisan('dereu:backfill-operator-echoes')->assertSuccessful();
    $event->update(['processed_at' => null]);
    $this->artisan('dereu:backfill-operator-echoes')->assertSuccessful();

    expect(ChannelMessage::count())->toBe(1);
});

test('пробный прогон ничего не пишет', function () {
    operatorEchoEvent();

    $this->artisan('dereu:backfill-operator-echoes', ['--dry-run' => true])->assertSuccessful();

    expect(ChannelMessage::count())->toBe(0);
});

test('бэкофилл не глушит бота задним числом', function () {
    // События исторические: включать по ним паузу значило бы замолчать для
    // контактов, с которыми сейчас никто не разговаривает.
    $contact = Contact::factory()->create(['phone' => '77774258186']);
    ChannelMessage::factory()->for($contact)->create(['created_at' => now()->subMinutes(3)]);
    operatorEchoEvent(['timestamp' => (string) now()->timestamp]);

    $this->artisan('dereu:backfill-operator-echoes')->assertSuccessful();

    expect(app(OperatorHandoff::class)->isActive($contact->fresh()))->toBeFalse()
        ->and($contact->fresh()->last_inbound_at)->toBeNull();
});

test('чужая компания в переписки не попадает', function () {
    // Тестовый номер делится между проектами: без этой проверки команда
    // завела бы в чаты чужой контакт и чужой разговор.
    config()->set('services.dereu.external_id', 'org_наша');
    DereuCompany::factory()->create(['external_id' => 'org_наша', 'dereu_company_id' => 'co_наша']);

    $event = operatorEchoEvent([], ['company_id' => 'co_соседа']);

    $this->artisan('dereu:backfill-operator-echoes')->assertSuccessful();

    expect(ChannelMessage::count())->toBe(0)
        ->and(Contact::count())->toBe(0)
        ->and($event->fresh()->processed_at)->not->toBeNull();
});
