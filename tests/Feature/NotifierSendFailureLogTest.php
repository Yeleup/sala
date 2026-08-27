<?php

use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\WhatsappTemplate;
use App\Services\CustomerRequestNotifier;
use App\Services\ListingRenewalNotifier;
use App\Services\WhatsappTemplateLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/**
 * Синхронный отказ отправки (429/5xx от Dereu) — тот же «бот замолчал»,
 * что и асинхронный отказ Meta, но нотифаеры хоронили его в warning:
 * мониторинг, считающий error-строки, таких инцидентов не видел.
 */
beforeEach(function () {
    config()->set('services.dereu.external_id', 'org_test');
    config()->set('services.dereu.base_url', 'https://api.dereu.test/api/v1');
    connectedDereuCompany();
    Http::preventStrayRequests();
    Http::fake(['api.dereu.test/*' => Http::response(['error' => 'rate limited'], 429)]);
});

test('отказ уведомления о заявке пишется в лог уровнем error', function () {
    WhatsappTemplate::factory()->approved()
        ->create(['name' => WhatsappTemplateLibrary::NEW_CUSTOMER_REQUEST]);
    $supplier = Contact::factory()->withOpenSessionWindow()->create();
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();
    $request = CustomerRequest::factory()->create([
        'contact_id' => Contact::factory()->create()->id,
        'listing_id' => $listing->id,
        'query_text' => 'нужен кран',
    ]);
    Log::spy();

    expect(app(CustomerRequestNotifier::class)->notifySupplier($request))->toBeFalse();

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'Failed to notify the supplier'))
        ->once();
});

test('отказ 30-дневного опроса пишется в лог уровнем error', function () {
    WhatsappTemplate::factory()->approved()
        ->create(['name' => WhatsappTemplateLibrary::LISTING_RENEWAL]);
    $supplier = Contact::factory()->withOpenSessionWindow()->create();
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();
    Log::spy();

    app(ListingRenewalNotifier::class)->sendPoll($listing);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'Failed to send the renewal poll'))
        ->once();
});
