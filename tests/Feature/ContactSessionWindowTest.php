<?php

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the session window is open within 24 hours of the last inbound message', function () {
    $contact = Contact::factory()->withOpenSessionWindow()->create();

    expect($contact->hasOpenSessionWindow())->toBeTrue();
});

test('the session window is closed when the last inbound message is older than 24 hours', function () {
    $contact = Contact::factory()->withClosedSessionWindow()->create();

    expect($contact->hasOpenSessionWindow())->toBeFalse();
});

test('the session window is closed exactly 24 hours after the last inbound message', function () {
    $this->freezeTime();
    $contact = Contact::factory()->create(['last_inbound_at' => now()->subDay()]);

    expect($contact->hasOpenSessionWindow())->toBeFalse();
});

test('a contact that never wrote has no session window', function () {
    $contact = Contact::factory()->create();

    expect($contact->hasOpenSessionWindow())->toBeFalse();
});
