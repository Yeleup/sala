<?php

use App\Filament\Pages\WhatsAppChat;
use App\Filament\Resources\Listings\Pages\ListListings;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;

test('только список объявлений занимает всю ширину окна', function () {
    expect((new ListListings)->getMaxContentWidth())->toBe(Width::Full);
});

test('остальные страницы админки сохраняют прежнюю ширину', function () {
    expect(Filament::getPanel('admin')->getMaxContentWidth())->toBeNull()
        ->and((new WhatsAppChat)->getMaxContentWidth())->toBeNull();
});
