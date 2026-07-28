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

test('телефон обязателен', function () {
    Livewire::test(CreateContact::class)
        ->fillForm(['phone' => ''])
        ->call('create')
        ->assertHasFormErrors(['phone']);

    expect(Contact::count())->toBe(0);
});

test('номер записывается как угодно и приводится к виду, в котором его знает WhatsApp', function (string $typed) {
    Livewire::test(CreateContact::class)
        ->fillForm(['phone' => $typed])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Contact::sole()->phone)->toBe('77011234567');
})->with([
    'как диктуют по телефону' => ['8 701 123 45 67'],
    'с плюсом и скобками' => ['+7 (701) 123-45-67'],
    'через дефисы' => ['7701-123-45-67'],
    'уже канонический' => ['77011234567'],
]);

test('любое написание уже заведённого номера упирается в тот же контакт — и он назван', function () {
    Contact::factory()->create(['phone' => '77011234567', 'display_name' => 'ТОО «СтройКран»']);

    Livewire::test(CreateContact::class)
        ->fillForm(['phone' => '8 701 123 45 67'])
        ->call('create')
        ->assertHasFormErrors(['phone' => 'Контакт с таким номером уже есть — «ТОО «СтройКран»».']);

    expect(Contact::count())->toBe(1);
});

test('редактирование контакта не спотыкается о его собственный номер', function () {
    $contact = Contact::factory()->create(['phone' => '77011234567']);

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->fillForm(['phone' => '8 701 123 45 67', 'profile_name' => 'Асхат'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->refresh())
        ->phone->toBe('77011234567')
        ->profile_name->toBe('Асхат');
});

test('недописанный номер не сохраняется', function () {
    // Маска позволяет отправить форму на половине набора — иначе в базу
    // легло бы «770123» как полноценный номер.
    Livewire::test(CreateContact::class)
        ->fillForm(['phone' => '+7(701)23'])
        ->call('create')
        ->assertHasFormErrors(['phone' => 'Номер не дописан — в казахстанском номере 11 цифр, формат +7(7XX)XXXXXXX.']);

    expect(Contact::count())->toBe(0);
});

test('иностранный номер, пришедший из WhatsApp, правится и сохраняется как есть', function () {
    $contact = Contact::factory()->create(['phone' => '49151123456']);

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->fillForm(['phone' => '49151123457', 'display_name' => 'Партнёр'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->refresh())
        ->phone->toBe('49151123457')
        ->display_name->toBe('Партнёр');
});

test('текст без номера телефоном не считается', function () {
    Livewire::test(CreateContact::class)
        ->fillForm(['phone' => '12'])
        ->call('create')
        ->assertHasFormErrors(['phone']);

    expect(Contact::count())->toBe(0);
});

test('оператор редактирует телефон, имя профиля и отображаемое имя', function () {
    $contact = Contact::factory()->create();

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->fillForm([
            'phone' => '77770001122',
            'profile_name' => 'Береке',
            'display_name' => 'ТОО «СтройКран»',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->refresh())
        ->phone->toBe('77770001122')
        ->profile_name->toBe('Береке')
        ->display_name->toBe('ТОО «СтройКран»');
});

test('очистка отображаемого имени возвращает показ имени профиля WhatsApp', function () {
    $contact = Contact::factory()->create(['profile_name' => 'Асхат', 'display_name' => 'ТОО «СтройКран»']);

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->fillForm(['display_name' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    $contact->refresh();
    expect($contact->display_name)->toBeNull()
        ->and($contact->displayName())->toBe('Асхат');
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
