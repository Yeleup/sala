<?php

use App\Enums\CustomerRequestStatus;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\WhatsappTemplate;
use App\Services\CustomerRequestPlacer;
use App\Services\DereuMessenger;
use App\Services\WhatsappTemplateLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function placer(): CustomerRequestPlacer
{
    return app(CustomerRequestPlacer::class);
}

describe('дедупликация с учётом владельца объявления', function () {
    test('повторный выбор при том же владельце не дублирует заявку и не дёргает поставщика', function () {
        $customer = Contact::factory()->create();
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();

        test()->mock(DereuMessenger::class)->shouldReceive('sendButtons')->once();

        $first = placer()->place($customer, $listing, 'кран');
        $second = placer()->place($customer, $listing, 'кран');

        expect($first->wasRecentlyCreated)->toBeTrue()
            ->and($second->is($first))->toBeTrue()
            ->and(CustomerRequest::count())->toBe(1);
    });

    test('pending-заявка прежнему владельцу не блокирует уведомление нового', function () {
        $customer = Contact::factory()->create();
        $oldSupplier = Contact::factory()->withOpenSessionWindow()->create();
        $newSupplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($oldSupplier, 'supplier')->create();

        test()->mock(DereuMessenger::class)
            ->shouldReceive('sendButtons')->twice()->withArgs(
                fn (Contact $contact): bool => $contact->is($listing->refresh()->supplier),
            );

        placer()->place($customer, $listing, 'кран');
        $listing->update(['contact_id' => $newSupplier->id]);

        $second = placer()->place($customer, $listing->refresh(), 'кран');

        expect($second->wasRecentlyCreated)->toBeTrue()
            ->and(CustomerRequest::count())->toBe(2);
    });

    test('pending-заявка без снимка поставщика (до миграции) не блокирует новую', function () {
        $customer = Contact::factory()->create();
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();
        CustomerRequest::create([
            'contact_id' => $customer->id, 'listing_id' => $listing->id, 'query_text' => 'кран',
        ]);

        test()->mock(DereuMessenger::class)->shouldReceive('sendButtons')->once();

        $request = placer()->place($customer, $listing, 'кран');

        expect($request->wasRecentlyCreated)->toBeTrue()
            ->and(CustomerRequest::count())->toBe(2);
    });

    test('заявка запоминает, какому поставщику ушло уведомление', function () {
        $customer = Contact::factory()->create();
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();

        test()->mock(DereuMessenger::class)->shouldReceive('sendButtons')->once();

        $request = placer()->place($customer, $listing, 'кран');

        expect($request->supplier_contact_id)->toBe($supplier->id);
    });
});

describe('недоставленное уведомление не блокирует повторную попытку', function () {
    test('провал уведомления закрывает заявку статусом «Без ответа», повторный выбор пробует снова', function () {
        $customer = Contact::factory()->create();
        $supplier = Contact::factory()->withClosedSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();

        // Окно закрыто, утверждённого шаблона нет — уведомить нечем.
        test()->mock(DereuMessenger::class);

        $failed = placer()->place($customer, $listing, 'кран');

        expect($failed->status)->toBe(CustomerRequestStatus::Expired);

        // Появился утверждённый шаблон — повторный выбор уведомляет по-настоящему.
        WhatsappTemplate::factory()->approved()->create([
            'name' => WhatsappTemplateLibrary::NEW_CUSTOMER_REQUEST, 'language' => 'ru',
        ]);
        test()->mock(DereuMessenger::class)->shouldReceive('sendTemplate')->once();

        $retried = placer()->place($customer, $listing, 'кран');

        expect($retried->wasRecentlyCreated)->toBeTrue()
            ->and($retried->status)->toBe(CustomerRequestStatus::Pending)
            ->and(CustomerRequest::count())->toBe(2);
    });

    test('успешно уведомлённая заявка остаётся ждать ответа', function () {
        $customer = Contact::factory()->create();
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();

        test()->mock(DereuMessenger::class)->shouldReceive('sendButtons')->once();

        $request = placer()->place($customer, $listing, 'кран');

        expect($request->status)->toBe(CustomerRequestStatus::Pending);
    });
});
