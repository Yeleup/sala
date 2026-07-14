<?php

namespace App\Filament\Resources\Listings\Pages;

use App\Filament\Resources\Listings\ListingResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditListing extends EditRecord
{
    protected static string $resource = ListingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Просмотр'),
            DeleteAction::make()
                ->label('Удалить')
                ->modalHeading('Удалить объявление?')
                ->modalDescription('Объявление удаляется безвозвратно вместе с медиа и заявками по нему.'),
        ];
    }
}
