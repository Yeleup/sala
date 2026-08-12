<?php

namespace App\Filament\Resources\Listings\Pages;

use App\Enums\ListingOrigin;
use App\Filament\Resources\Listings\ListingResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

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
        $data['origin'] = ListingOrigin::Operator;
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }

    /**
     * One call often brings a whole fleet — five excavators from the same
     * company in the same place at the same rate. What such listings share
     * stays in the form; what tells them apart (the name, the description
     * and the photos) is cleared, so the next one cannot be saved as a
     * copy of the previous by accident.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preserveFormDataWhenCreatingAnother(array $data): array
    {
        return Arr::only($data, [
            'kind',
            'contact_id',
            'category_id',
            'brand_id',
            'location_id',
            'location_detail',
            'price',
        ]);
    }
}
