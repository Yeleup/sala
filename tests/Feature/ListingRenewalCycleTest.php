<?php

use App\Enums\ListingStatus;
use App\Models\Contact;
use App\Models\Listing;
use App\Models\WhatsappTemplate;
use App\Services\DereuMessenger;
use App\Services\WhatsappTemplateLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function fakeCycleMessenger(): MockInterface
{
    return test()->mock(DereuMessenger::class);
}

function expiringListing(array $supplierStates = ['withOpenSessionWindow']): Listing
{
    $supplier = Contact::factory();
    foreach ($supplierStates as $state) {
        $supplier = $supplier->{$state}();
    }

    return Listing::factory()
        ->published()
        ->for($supplier->create(), 'supplier')
        ->create(['category_id' => categoryNamed('Автокран')->id, 'expires_at' => now()->addHours(12)]);
}

describe('ежедневный опрос актуальности', function () {
    test('за сутки до истечения поставщику уходят кнопки, опрос помечается отправленным', function () {
        $listing = expiringListing();

        $messenger = fakeCycleMessenger();
        $messenger->shouldReceive('sendButtons')->once()->withArgs(function (Contact $contact, string $text, array $buttons) use ($listing): bool {
            return $contact->is($listing->supplier)
                && str_contains($text, 'Автокран')
                && str_contains($text, 'актуально')
                && $buttons[0] === ['id' => "renewal_yes:{$listing->id}", 'title' => 'Да, актуально']
                && $buttons[1] === ['id' => "renewal_no:{$listing->id}", 'title' => 'Нет, в архив'];
        });

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();

        expect($listing->refresh()->renewal_requested_at)->not->toBeNull();
    });

    test('повторный запуск не шлёт опрос второй раз', function () {
        $listing = expiringListing();
        $listing->update(['renewal_requested_at' => now()->subHours(2)]);

        fakeCycleMessenger()->shouldNotReceive('sendButtons');

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();
    });

    test('вне окна опрос уходит утверждённым шаблоном с payload кнопок', function () {
        $listing = expiringListing(['withClosedSessionWindow']);
        $template = WhatsappTemplate::factory()->approved()->create([
            'name' => WhatsappTemplateLibrary::LISTING_RENEWAL,
            'language' => 'ru',
        ]);

        $messenger = fakeCycleMessenger();
        $messenger->shouldReceive('sendTemplate')->once()->withArgs(
            fn (Contact $contact, WhatsappTemplate $sent, array $params, array $payloads): bool => $sent->is($template)
                && $params === ['Автокран']
                && $payloads === ["renewal_yes:{$listing->id}", "renewal_no:{$listing->id}"],
        );

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();

        expect($listing->refresh()->renewal_requested_at)->not->toBeNull();
    });

    test('без утверждённого шаблона опрос откладывается и будет повторён завтра', function () {
        $listing = expiringListing(['withClosedSessionWindow']);

        fakeCycleMessenger()->shouldNotReceive('sendTemplate');

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();

        expect($listing->refresh()->renewal_requested_at)->toBeNull();
    });

    test('истёкшее без подтверждения объявление автоматически архивируется', function () {
        $expired = Listing::factory()->expired()->create();
        $active = Listing::factory()->published()->create(['expires_at' => now()->addDays(10)]);

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();

        expect($expired->refresh()->status)->toBe(ListingStatus::Archived)
            ->and($active->refresh()->status)->toBe(ListingStatus::Published);
    });

    test('продление сбрасывает отметку опроса — следующий цикл спросит снова', function () {
        $listing = Listing::factory()->published()->create(['renewal_requested_at' => now()]);

        $listing->renew();

        expect($listing->refresh()->renewal_requested_at)->toBeNull()
            ->and($listing->expires_at->isAfter(now()->addDays(29)))->toBeTrue();
    });
});

