<?php

use App\Models\ChannelMessage;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\WhatsappTemplate;
use App\Services\CustomerRequestNotifier;
use App\Services\ListingModerationNotifier;
use App\Services\ListingRenewalNotifier;
use App\Services\WhatsappTemplateLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Каждое проактивное уведомление, ушедшее сессионным сообщением в открытое
 * по нашим часам окно, обязано нести шаблонный фолбэк: окно может закрыться
 * между проверкой и обработкой в Meta, и асинхронный отказ тогда
 * перепосылает уведомление платным шаблоном — вместо тихой потери.
 */
beforeEach(function () {
    config()->set('services.dereu.external_id', 'org_test');
    config()->set('services.dereu.base_url', 'https://api.dereu.test/api/v1');
    connectedDereuCompany();
    Http::preventStrayRequests();
    Http::fake([
        'api.dereu.test/*' => fn () => Http::response(['id' => (string) Str::uuid(), 'status' => 'queued'], 202),
    ]);
});

test('уведомление о заявке в открытое окно несёт шаблонный фолбэк', function () {
    $template = WhatsappTemplate::factory()->approved()
        ->create(['name' => WhatsappTemplateLibrary::NEW_CUSTOMER_REQUEST]);
    $supplier = Contact::factory()->withOpenSessionWindow()->create();
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();
    $request = CustomerRequest::factory()->create([
        'contact_id' => Contact::factory()->create()->id,
        'listing_id' => $listing->id,
        'query_text' => 'нужен кран',
    ]);

    app(CustomerRequestNotifier::class)->notifySupplier($request);

    $fallback = ChannelMessage::sole()->template_fallback;
    expect($fallback['whatsapp_template_id'])->toBe($template->id)
        ->and($fallback['button_payloads'])->toHaveCount(2);
});

test('30-дневный опрос в открытое окно несёт шаблонный фолбэк', function () {
    $template = WhatsappTemplate::factory()->approved()
        ->create(['name' => WhatsappTemplateLibrary::LISTING_RENEWAL]);
    $supplier = Contact::factory()->withOpenSessionWindow()->create();
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();

    app(ListingRenewalNotifier::class)->sendPoll($listing);

    $fallback = ChannelMessage::sole()->template_fallback;
    expect($fallback['whatsapp_template_id'])->toBe($template->id)
        ->and($fallback['button_payloads'])->toHaveCount(2);
});

test('вердикт модерации в открытое окно несёт шаблонный фолбэк', function () {
    $template = WhatsappTemplate::factory()->approved()
        ->create(['name' => WhatsappTemplateLibrary::LISTING_APPROVED]);
    $supplier = Contact::factory()->withOpenSessionWindow()->create();
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();

    app(ListingModerationNotifier::class)->notifyApproved($listing);

    $fallback = ChannelMessage::sole()->template_fallback;
    expect($fallback['whatsapp_template_id'])->toBe($template->id)
        ->and($fallback['button_payloads'])->toHaveCount(1);
});

test('без шаблона в реестре сессионное уведомление уходит без фолбэка, как раньше', function () {
    $supplier = Contact::factory()->withOpenSessionWindow()->create();
    $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();

    app(ListingRenewalNotifier::class)->sendPoll($listing);

    expect(ChannelMessage::sole()->template_fallback)->toBeNull();
});
