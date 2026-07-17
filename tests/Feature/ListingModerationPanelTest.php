<?php

use App\Enums\ListingStatus;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\CustomerRequests\CustomerRequestResource;
use App\Filament\Resources\CustomerRequests\Pages\ListCustomerRequests;
use App\Filament\Resources\Listings\ListingResource;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Filament\Resources\Listings\Pages\ViewListing;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    // Одобрение синхронно (очередь sync) запускает генерацию эмбеддинга.
    Embeddings::fake();
});

test('guests are redirected to the panel login', function () {
    auth()->logout();

    $this->get(ListingResource::getUrl('index'))->assertRedirect();
});

test('the listings table shows the moderation queue by default', function () {
    $pending = Listing::factory()->pendingModeration()->create();
    $published = Listing::factory()->published()->create();

    Livewire::test(ListListings::class)
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$published]);
});

test('approving from the table publishes the listing for 30 days', function () {
    $this->freezeTime();
    $listing = Listing::factory()->pendingModeration()->create();

    Livewire::test(ListListings::class)
        ->callAction(TestAction::make('approve')->table($listing))
        ->assertNotified('Объявление опубликовано');

    $listing->refresh();
    expect($listing->status)->toBe(ListingStatus::Published)
        ->and($listing->expires_at->toDateTimeString())->toBe(now()->addDays(30)->toDateTimeString());
});

test('rejecting from the table requires a reason', function () {
    $listing = Listing::factory()->pendingModeration()->create();

    Livewire::test(ListListings::class)
        ->callAction(TestAction::make('reject')->table($listing), ['rejection_reason' => ''])
        ->assertHasActionErrors(['rejection_reason' => ['required']]);

    expect($listing->refresh()->status)->toBe(ListingStatus::PendingModeration);
});

test('rejecting from the table stores the reason', function () {
    $listing = Listing::factory()->pendingModeration()->create();

    Livewire::test(ListListings::class)
        ->callAction(TestAction::make('reject')->table($listing), ['rejection_reason' => 'Нет цены'])
        ->assertNotified('Объявление отклонено');

    $listing->refresh();
    expect($listing->status)->toBe(ListingStatus::Rejected)
        ->and($listing->rejection_reason)->toBe('Нет цены');
});

test('the view page shows media and offers moderation actions for a pending listing', function () {
    $listing = Listing::factory()
        ->pendingModeration()
        ->has(ListingMedia::factory(), 'media')
        ->has(ListingMedia::factory()->audio(), 'media')
        ->create();

    Livewire::test(ViewListing::class, ['record' => $listing->getRouteKey()])
        ->assertSee('Сдаю в аренду автокран 25 тонн, нахожусь в Шымкенте, цена договорная.')
        ->assertActionVisible('approve')
        ->assertActionVisible('reject')
        ->callAction('approve');

    expect($listing->refresh()->status)->toBe(ListingStatus::Published);
});

test('moderation actions are hidden for an already published listing', function () {
    $listing = Listing::factory()->published()->create();

    Livewire::test(ViewListing::class, ['record' => $listing->getRouteKey()])
        ->assertActionHidden('approve')
        ->assertActionHidden('reject');
});

test('the contacts list is available for viewing', function () {
    $contacts = Contact::factory()->count(2)->create();

    $this->get(ContactResource::getUrl('index'))->assertOk();

    Livewire::test(ListContacts::class)->assertCanSeeTableRecords($contacts);
});

test('the customer requests list is available for viewing', function () {
    $request = CustomerRequest::factory()->create();

    $this->get(CustomerRequestResource::getUrl('index'))->assertOk();

    Livewire::test(ListCustomerRequests::class)
        ->assertCanSeeTableRecords([$request])
        ->assertSee('Ожидает ответа');
});
