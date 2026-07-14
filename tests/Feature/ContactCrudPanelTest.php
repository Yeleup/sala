<?php

use App\Enums\ListingMediaType;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Models\BotSession;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('оператор создаёт контакт заранее — до первого сообщения боту', function () {
    Livewire::test(CreateContact::class)
        ->fillForm([
            'phone' => '77011234567',
            'profile_name' => 'Асхат',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $contact = Contact::sole();
    expect($contact)
        ->phone->toBe('77011234567')
        ->profile_name->toBe('Асхат')
        ->last_inbound_at->toBeNull()
        ->and($contact->hasOpenSessionWindow())->toBeFalse();
});

test('телефон обязателен, только из цифр и уникален', function () {
    Contact::factory()->create(['phone' => '77011234567']);

    Livewire::test(CreateContact::class)
        ->fillForm(['phone' => ''])
        ->call('create')
        ->assertHasFormErrors(['phone']);

    Livewire::test(CreateContact::class)
        ->fillForm(['phone' => '+7 701 123-45-67'])
        ->call('create')
        ->assertHasFormErrors(['phone']);

    Livewire::test(CreateContact::class)
        ->fillForm(['phone' => '77011234567'])
        ->call('create')
        ->assertHasFormErrors(['phone']);

    expect(Contact::count())->toBe(1);
});

test('оператор редактирует телефон и имя профиля', function () {
    $contact = Contact::factory()->create();

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->fillForm([
            'phone' => '77770001122',
            'profile_name' => 'Береке',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->refresh())
        ->phone->toBe('77770001122')
        ->profile_name->toBe('Береке');
});

test('удаление контакта уносит его объявления с файлами, заявки и сессию', function () {
    Storage::fake('public');
    $contact = Contact::factory()->create();
    $listing = Listing::factory()->for($contact, 'supplier')->create();
    Storage::disk('public')->put("listings/{$listing->id}/photos/photo.jpg", 'JPEG');
    ListingMedia::create([
        'listing_id' => $listing->id,
        'type' => ListingMediaType::Photo,
        'path' => "listings/{$listing->id}/photos/photo.jpg",
    ]);
    CustomerRequest::factory()->create(['contact_id' => $contact->id]);
    BotSession::factory()->create(['contact_id' => $contact->id]);

    Livewire::test(ListContacts::class)
        ->callAction(TestAction::make('delete')->table($contact));

    // Объявление чужого поставщика из фабрики заявки остаётся — удаляется
    // только принадлежащее контакту.
    expect(Contact::whereKey($contact->id)->exists())->toBeFalse()
        ->and(Listing::whereKey($listing->id)->exists())->toBeFalse()
        ->and(ListingMedia::count())->toBe(0)
        ->and(CustomerRequest::where('contact_id', $contact->id)->exists())->toBeFalse()
        ->and(BotSession::where('contact_id', $contact->id)->exists())->toBeFalse();
    Storage::disk('public')->assertMissing("listings/{$listing->id}/photos/photo.jpg");
});

test('bulk-удаление стирает только выбранные контакты', function () {
    $removed = Contact::factory()->count(2)->create();
    $kept = Contact::factory()->create();

    Livewire::test(ListContacts::class)
        ->selectTableRecords($removed->pluck('id')->all())
        ->callAction(TestAction::make('delete')->table()->bulk());

    expect(Contact::pluck('id')->all())->toBe([$kept->id]);
});
