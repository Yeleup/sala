<?php

namespace App\Filament\Resources\Listings;

use App\Enums\ListingStatus;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Filament\Resources\Listings\Pages\ViewListing;
use App\Filament\Resources\Listings\Schemas\ListingInfolist;
use App\Filament\Resources\Listings\Tables\ListingsTable;
use App\Models\Listing;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Moderation queue: the operator approves or rejects listings submitted by
 * suppliers. Listing content is edited only by the supplier via the web
 * interface, so apart from the moderation actions the resource is read-only.
 */
class ListingResource extends Resource
{
    protected static ?string $model = Listing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $modelLabel = 'объявление';

    protected static ?string $pluralModelLabel = 'объявления';

    protected static ?int $navigationSort = 1;

    public static function infolist(Schema $schema): Schema
    {
        return ListingInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ListingsTable::configure($table);
    }

    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Одобрить')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Listing $record): bool => $record->status === ListingStatus::PendingModeration)
            ->requiresConfirmation()
            ->modalHeading('Опубликовать объявление?')
            ->modalDescription('Объявление попадёт в поиск на '.Listing::LIFETIME_DAYS.' дней.')
            ->action(function (Listing $record): void {
                $record->approve();

                Notification::make()
                    ->title('Объявление опубликовано')
                    ->success()
                    ->send();
            });
    }

    public static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Отклонить')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Listing $record): bool => $record->status === ListingStatus::PendingModeration)
            ->modalHeading('Отклонить объявление')
            ->modalDescription('Причину увидит поставщик в веб-интерфейсе.')
            ->schema([
                Textarea::make('rejection_reason')
                    ->label('Причина отклонения')
                    ->required(),
            ])
            ->action(function (Listing $record, array $data): void {
                $record->reject($data['rejection_reason']);

                Notification::make()
                    ->title('Объявление отклонено')
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListListings::route('/'),
            'view' => ViewListing::route('/{record}'),
        ];
    }
}
