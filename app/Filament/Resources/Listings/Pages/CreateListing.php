<?php

namespace App\Filament\Resources\Listings\Pages;

use App\Filament\Resources\Listings\ListingResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * An operator-created listing starts as a draft, like an AI-collected one:
 * publication still goes through «На модерацию» → «Одобрить».
 */
class CreateListing extends CreateRecord
{
    protected static string $resource = ListingResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ListingResource::dropBrandForService($data);
    }
}
