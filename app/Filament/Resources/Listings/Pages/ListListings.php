<?php

namespace App\Filament\Resources\Listings\Pages;

use App\Enums\ListingStatus;
use App\Filament\Resources\Listings\ListingResource;
use App\Models\Contact;
use App\Services\FleetUpdateBroadcaster;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListListings extends ListRecords
{
    protected static string $resource = ListingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fleetUpdateBroadcast')
                ->label('Рассылка актуализации')
                ->icon(Heroicon::OutlinedMegaphone)
                ->requiresConfirmation()
                ->modalHeading('Разослать просьбу актуализировать парк?')
                ->modalDescription(fn (): string => sprintf(
                    'Сообщение уйдёт %d поставщикам с опубликованными объявлениями: в открытое 24-часовое окно — бесплатно, остальным — платным шаблоном fleet_status_update.',
                    Contact::query()
                        ->whereHas('listings', fn ($query) => $query->where('status', ListingStatus::Published))
                        ->count(),
                ))
                ->action(function (): void {
                    $result = app(FleetUpdateBroadcaster::class)->broadcast();

                    Notification::make()
                        ->title("Рассылка запущена: отправлено {$result['sent']}, не доставлено {$result['failed']}")
                        ->body($result['failed'] > 0 ? 'Причины недоставки — в журнале приложения (обычно нет утверждённого шаблона или opt-in).' : null)
                        ->{$result['failed'] > 0 ? 'warning' : 'success'}()
                        ->send();
                }),
        ];
    }
}
