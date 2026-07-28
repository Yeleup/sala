<?php

namespace App\Filament\Resources\Listings\Pages;

use App\Filament\Resources\Listings\ListingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * The only record page: it replaces the read-only view, so the moderation
 * verdict is given from here.
 */
class EditListing extends EditRecord
{
    protected static string $resource = ListingResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ListingResource::dropBrandForService($data);
    }

    protected function getHeaderActions(): array
    {
        return [
            ListingResource::previewAction(),
            ListingResource::publishAction(),
            ListingResource::submitForModerationAction(),
            ListingResource::approveAction(),
            ListingResource::rejectAction(),
            ListingResource::renewAction(),
            ListingResource::archiveAction(),
            DeleteAction::make()
                ->label('Удалить')
                ->modalHeading('Удалить объявление?')
                ->modalDescription('Объявление удаляется безвозвратно вместе с медиа и заявками по нему.'),
        ];
    }
}
