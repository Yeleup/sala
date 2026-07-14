<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditContact extends EditRecord
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Просмотр'),
            DeleteAction::make()
                ->label('Удалить')
                ->modalHeading('Удалить контакт?')
                ->modalDescription('Контакт удаляется безвозвратно вместе с его объявлениями, заявками и историей диалога.'),
        ];
    }
}
