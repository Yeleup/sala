<?php

namespace App\Filament\Resources\Listings\Pages;

use App\Filament\Resources\Listings\ListingResource;
use Filament\Resources\Pages\ViewRecord;

class ViewListing extends ViewRecord
{
    protected static string $resource = ListingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ListingResource::approveAction(),
            ListingResource::rejectAction(),
        ];
    }
}
