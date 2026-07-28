<?php

use App\Enums\ListingStatus;
use App\Exceptions\SessionWindowClosed;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\CustomerRequests\CustomerRequestResource;
use App\Filament\Resources\CustomerRequests\Pages\ListCustomerRequests;
use App\Filament\Resources\Listings\ListingResource;
use App\Filament\Resources\Listings\Pages\EditListing;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\User;
use App\Models\WhatsappTemplate;
use App\Services\DereuMessenger;
use App\Services\WhatsappTemplateLibrary;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Livewire\Livewire;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function fakeModerationMessenger(): MockInterface
{
    return test()->mock(DereuMessenger::class);
}

function pendingModerationListing(string $supplierWindowState): Listing
{
    $supplier = Contact::factory()->{$supplierWindowState}()->create();

    return Listing::factory()
        ->pendingModeration()
        ->for($supplier, 'supplier')
        ->create(['category_id' => categoryNamed('Автокран')->id]);
}

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

test('the edit page shows media and offers moderation actions for a pending listing', function () {
    $listing = Listing::factory()
        ->pendingModeration()
        ->has(ListingMedia::factory(), 'media')
        ->has(ListingMedia::factory()->audio(), 'media')
        ->create();

    Livewire::test(EditListing::class, ['record' => $listing->getRouteKey()])
        ->assertSee('Сдаю в аренду автокран 25 тонн, нахожусь в Шымкенте, цена договорная.')
        ->assertActionVisible('approve')
        ->assertActionVisible('reject')
        ->callAction('approve');

    expect($listing->refresh()->status)->toBe(ListingStatus::Published);
});

test('moderation actions are hidden for an already published listing', function () {
    $listing = Listing::factory()->published()->create();

    Livewire::test(EditListing::class, ['record' => $listing->getRouteKey()])
        ->assertActionHidden('approve')
        ->assertActionHidden('reject');
});

test('одобрение из очереди записывает, кто вынес вердикт', function () {
    $moderator = User::factory()->create();
    $this->actingAs($moderator);
    $listing = Listing::factory()->pendingModeration()->create();

    Livewire::test(ListListings::class)
        ->callAction(TestAction::make('approve')->table($listing));

    expect($listing->refresh())
        ->moderated_by_user_id->toBe($moderator->id)
        ->and($listing->moderated_at)->not->toBeNull();
});

test('оператор снимает объявление с публикации в архив, не удаляя его', function () {
    $listing = Listing::factory()->published()->create();
    $request = CustomerRequest::factory()->create(['listing_id' => $listing->id]);

    Livewire::test(ListListings::class)
        ->filterTable('status', ListingStatus::Published->value)
        ->callAction(TestAction::make('archive')->table($listing))
        ->assertNotified('Объявление в архиве');

    // В отличие от удаления архив сохраняет и объявление, и заявки по нему.
    expect($listing->refresh()->status)->toBe(ListingStatus::Archived)
        ->and(CustomerRequest::whereKey($request->id)->exists())->toBeTrue();
});

test('оператор продлевает объявление после звонка поставщику', function () {
    $this->freezeTime();
    $listing = Listing::factory()->published()->create([
        'expires_at' => now()->addDay(),
        'renewal_requested_at' => now(),
    ]);

    Livewire::test(ListListings::class)
        ->filterTable('status', ListingStatus::Published->value)
        ->callAction(TestAction::make('renew')->table($listing));

    $listing->refresh();
    expect($listing->expires_at->toDateTimeString())->toBe(now()->addDays(30)->toDateTimeString())
        ->and($listing->status)->toBe(ListingStatus::Published)
        // Следующий цикл должен спросить снова.
        ->and($listing->renewal_requested_at)->toBeNull();
});

test('фильтр «истекает в сутки» собирает объявления, которые пора продлевать', function () {
    $expiringSoon = Listing::factory()->published()->create(['expires_at' => now()->addHours(6)]);
    $freshlyPublished = Listing::factory()->published()->create(['expires_at' => now()->addDays(30)]);
    $alreadyExpired = Listing::factory()->expired()->create();

    // Все три опубликованы — отбор делает именно фильтр срока, а не статус
    // (по умолчанию список показывает очередь модерации).
    Livewire::test(ListListings::class)
        ->filterTable('status', ListingStatus::Published->value)
        ->filterTable('expiring')
        ->assertCanSeeTableRecords([$expiringSoon])
        ->assertCanNotSeeTableRecords([$freshlyPublished, $alreadyExpired]);
});

test('в списке видно, писал ли поставщик боту', function () {
    $silent = Listing::factory()
        ->for(Contact::factory()->create(['last_inbound_at' => null]), 'supplier')
        ->create();
    $wrote = Listing::factory()
        ->for(Contact::factory()->create(['last_inbound_at' => now()->subWeek()]), 'supplier')
        ->create();

    Livewire::test(ListListings::class)
        ->filterTable('status', ListingStatus::Draft->value)
        ->assertTableColumnStateSet('supplier_wrote', false, $silent)
        // Окно 24 ч уже закрыто, но писал он всё же — колонка про это, а не про окно.
        ->assertTableColumnStateSet('supplier_wrote', true, $wrote);
});

test('архив и продление недоступны, пока объявление не опубликовано', function () {
    $listing = Listing::factory()->pendingModeration()->create();

    Livewire::test(EditListing::class, ['record' => $listing->getRouteKey()])
        ->assertActionHidden('archive')
        ->assertActionHidden('renew');
});

describe('уведомление поставщика о вердикте модерации', function () {
    test('при одобрении в открытое окно уходит CTA-ссылка на объявление', function () {
        $listing = pendingModerationListing('withOpenSessionWindow');

        $messenger = fakeModerationMessenger();
        $messenger->shouldReceive('sendCtaUrl')->once()->withArgs(
            fn (Contact $contact, string $text, string $button, string $url): bool => $contact->is($listing->supplier)
                && str_contains($text, 'Автокран')
                && str_contains($text, 'опубликовано')
                && $button === 'Открыть объявление'
                && str_contains($url, "/supplier/listings/{$listing->id}/edit")
                && str_contains($url, 'signature='),
        );

        Livewire::test(ListListings::class)
            ->callAction(TestAction::make('approve')->table($listing))
            ->assertNotified(Notification::make()
                ->title('Объявление опубликовано')
                ->body('Поставщику отправлено уведомление в WhatsApp.')
                ->success());

        expect($listing->refresh()->status)->toBe(ListingStatus::Published);
    });

    test('при отклонении в открытое окно уходит CTA-ссылка, причина в сообщение не попадает', function () {
        $listing = pendingModerationListing('withOpenSessionWindow');

        $messenger = fakeModerationMessenger();
        $messenger->shouldReceive('sendCtaUrl')->once()->withArgs(
            fn (Contact $contact, string $text, string $button, string $url): bool => $contact->is($listing->supplier)
                && str_contains($text, 'не прошло модерацию')
                && ! str_contains($text, 'Нет цены')
                && str_contains($url, "/supplier/listings/{$listing->id}/edit"),
        );

        Livewire::test(ListListings::class)
            ->callAction(TestAction::make('reject')->table($listing), ['rejection_reason' => 'Нет цены'])
            ->assertNotified('Объявление отклонено');

        expect($listing->refresh()->status)->toBe(ListingStatus::Rejected);
    });

    test('вне окна одобрение уходит утверждённым шаблоном с кнопкой «Открыть объявление»', function () {
        $listing = pendingModerationListing('withClosedSessionWindow');
        $template = WhatsappTemplate::factory()->approved()->create([
            'name' => WhatsappTemplateLibrary::LISTING_APPROVED,
            'language' => 'ru',
        ]);
        // Шаблон противоположного вердикта тоже утверждён — выбор строго по имени.
        WhatsappTemplate::factory()->approved()->create([
            'name' => WhatsappTemplateLibrary::LISTING_REJECTED,
            'language' => 'ru',
        ]);

        $messenger = fakeModerationMessenger();
        $messenger->shouldReceive('sendTemplate')->once()->withArgs(
            fn (Contact $contact, WhatsappTemplate $sent, array $params, array $payloads): bool => $contact->is($listing->supplier)
                && $sent->is($template)
                && $params === ['Автокран']
                && $payloads === ["listing_open:{$listing->id}"],
        );

        Livewire::test(ListListings::class)
            ->callAction(TestAction::make('approve')->table($listing));

        expect($listing->refresh()->status)->toBe(ListingStatus::Published);
    });

    test('вне окна отклонение уходит шаблоном listing_rejected', function () {
        $listing = pendingModerationListing('withClosedSessionWindow');
        $template = WhatsappTemplate::factory()->approved()->create([
            'name' => WhatsappTemplateLibrary::LISTING_REJECTED,
            'language' => 'ru',
        ]);
        // Шаблон противоположного вердикта тоже утверждён — выбор строго по имени.
        WhatsappTemplate::factory()->approved()->create([
            'name' => WhatsappTemplateLibrary::LISTING_APPROVED,
            'language' => 'ru',
        ]);

        $messenger = fakeModerationMessenger();
        $messenger->shouldReceive('sendTemplate')->once()->withArgs(
            fn (Contact $contact, WhatsappTemplate $sent, array $params, array $payloads): bool => $sent->is($template)
                && $params === ['Автокран']
                && $payloads === ["listing_open:{$listing->id}"],
        );

        Livewire::test(ListListings::class)
            ->callAction(TestAction::make('reject')->table($listing), ['rejection_reason' => 'Нет цены']);

        expect($listing->refresh()->status)->toBe(ListingStatus::Rejected);
    });

    test('без утверждённого шаблона вердикт применяется, но уведомление не уходит', function () {
        $listing = pendingModerationListing('withClosedSessionWindow');

        $messenger = fakeModerationMessenger();
        $messenger->shouldNotReceive('sendTemplate');
        $messenger->shouldNotReceive('sendCtaUrl');

        Livewire::test(ListListings::class)
            ->callAction(TestAction::make('approve')->table($listing))
            ->assertNotified(Notification::make()
                ->title('Объявление опубликовано')
                ->body('Уведомить поставщика в WhatsApp не удалось — статус он увидит в веб-кабинете.')
                ->success());

        expect($listing->refresh()->status)->toBe(ListingStatus::Published);
    });

    test('отклонение без утверждённого шаблона тоже применяется, оператор видит, что уведомление не ушло', function () {
        $listing = pendingModerationListing('withClosedSessionWindow');

        $messenger = fakeModerationMessenger();
        $messenger->shouldNotReceive('sendTemplate');
        $messenger->shouldNotReceive('sendCtaUrl');

        Livewire::test(ListListings::class)
            ->callAction(TestAction::make('reject')->table($listing), ['rejection_reason' => 'Нет цены'])
            ->assertNotified(Notification::make()
                ->title('Объявление отклонено')
                ->body('Уведомить поставщика в WhatsApp не удалось — причину он увидит в веб-кабинете.')
                ->success());

        expect($listing->refresh()->status)->toBe(ListingStatus::Rejected);
    });

    test('если окно закрылось между проверкой и отправкой, уведомление уходит шаблоном', function () {
        $listing = pendingModerationListing('withOpenSessionWindow');
        $template = WhatsappTemplate::factory()->approved()->create([
            'name' => WhatsappTemplateLibrary::LISTING_APPROVED,
            'language' => 'ru',
        ]);

        $messenger = fakeModerationMessenger();
        $messenger->shouldReceive('sendCtaUrl')->once()->andThrow(
            new SessionWindowClosed($listing->supplier),
        );
        $messenger->shouldReceive('sendTemplate')->once()->withArgs(
            fn (Contact $contact, WhatsappTemplate $sent): bool => $sent->is($template),
        );

        Livewire::test(ListListings::class)
            ->callAction(TestAction::make('approve')->table($listing));

        expect($listing->refresh()->status)->toBe(ListingStatus::Published);
    });

    test('сбой отправки не мешает модерации', function () {
        $listing = pendingModerationListing('withOpenSessionWindow');

        $messenger = fakeModerationMessenger();
        $messenger->shouldReceive('sendCtaUrl')->once()->andThrow(new RuntimeException('Dereu недоступен'));

        Livewire::test(ListListings::class)
            ->callAction(TestAction::make('approve')->table($listing))
            ->assertNotified('Объявление опубликовано');

        expect($listing->refresh()->status)->toBe(ListingStatus::Published);
    });
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
