<?php

use App\Filament\Pages\WhatsAppChat;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Listings\ListingResource;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('только список объявлений занимает всю ширину окна', function () {
    expect((new ListListings)->getMaxContentWidth())->toBe(Width::Full);
});

test('остальные страницы админки сохраняют прежнюю ширину', function () {
    expect(Filament::getPanel('admin')->getMaxContentWidth())->toBeNull()
        ->and((new WhatsAppChat)->getMaxContentWidth())->toBeNull();
});

test('вторая полоса прокрутки есть только над таблицей объявлений', function () {
    $this->actingAs(User::factory()->create());

    $this->get(ListingResource::getUrl('index'))->assertSee('fi-lts', escape: false);
    $this->get(ContactResource::getUrl('index'))->assertDontSee('fi-lts', escape: false);
});
