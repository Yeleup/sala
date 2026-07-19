<?php

namespace App\Filament\Resources\Listings;

use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Filament\Resources\Listings\Pages\CreateListing;
use App\Filament\Resources\Listings\Pages\EditListing;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Filament\Resources\Listings\Pages\ViewListing;
use App\Filament\Resources\Listings\Schemas\ListingForm;
use App\Filament\Resources\Listings\Schemas\ListingInfolist;
use App\Filament\Clusters\Marketplace\MarketplaceCluster;
use App\Filament\Resources\Listings\Tables\ListingsTable;
use App\Models\Listing;
use App\Services\ListingModerationNotifier;
use BackedEnum;
use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Listings in the admin: the moderation queue (approve/reject) plus full
 * CRUD for the operator — creating a listing on a supplier's behalf,
 * editing its business fields and deleting (including bulk delete).
 * Status transitions stay behind the dedicated lifecycle actions.
 */
class ListingResource extends Resource
{
    protected static ?string $model = Listing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $cluster = MarketplaceCluster::class;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $modelLabel = 'объявление';

    protected static ?string $pluralModelLabel = 'объявления';

    protected static ?int $navigationSort = 1;

    /**
     * Pre-title listings would otherwise be headed by the bare model label
     * («объявление») — the category-name fallback keeps them recognizable.
     *
     * @param  ?Listing  $record
     */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record?->displayName() ?? static::getModelLabel();
    }

    /**
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'category.name'];
    }

    public static function form(Schema $schema): Schema
    {
        return ListingForm::configure($schema);
    }

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

                $notified = app(ListingModerationNotifier::class)->notifyApproved($record);

                Notification::make()
                    ->title('Объявление опубликовано')
                    ->body($notified
                        ? 'Поставщику отправлено уведомление в WhatsApp.'
                        : 'Уведомить поставщика в WhatsApp не удалось — статус он увидит в веб-кабинете.')
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
            ->modalDescription('Поставщик получит уведомление в WhatsApp; причину он увидит по ссылке в веб-кабинете.')
            ->schema([
                Textarea::make('rejection_reason')
                    ->label('Причина отклонения')
                    ->required(),
            ])
            ->action(function (Listing $record, array $data): void {
                $record->reject($data['rejection_reason']);

                $notified = app(ListingModerationNotifier::class)->notifyRejected($record);

                Notification::make()
                    ->title('Объявление отклонено')
                    ->body($notified
                        ? 'Поставщику отправлено уведомление в WhatsApp.'
                        : 'Уведомить поставщика в WhatsApp не удалось — причину он увидит в веб-кабинете.')
                    ->success()
                    ->send();
            });
    }

    /**
     * A draft or a rejected listing goes to the moderation queue; without
     * this the operator could not publish a listing created in the admin.
     */
    public static function submitForModerationAction(): Action
    {
        return Action::make('submitForModeration')
            ->label('На модерацию')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn (Listing $record): bool => in_array($record->status, [ListingStatus::Draft, ListingStatus::Rejected], true))
            ->requiresConfirmation()
            ->modalHeading('Отправить на модерацию?')
            ->modalDescription('Объявление попадёт в очередь модерации, откуда его можно одобрить или отклонить.')
            ->action(function (Listing $record): void {
                $record->submitForModeration();

                Notification::make()
                    ->title('Объявление отправлено на модерацию')
                    ->success()
                    ->send();
            });
    }

    /**
     * Services carry no brand. The brand field is hidden for «услуга» and
     * hidden fields are not dehydrated, so the form data cannot be trusted
     * to clear a stale brand — it must be dropped here on save.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function dropBrandForService(array $data): array
    {
        $type = $data['type'] ?? null;
        $type = $type instanceof ListingType ? $type : ListingType::tryFrom((string) $type);

        if ($type === ListingType::Service) {
            $data['brand_id'] = null;
        }

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListListings::route('/'),
            'create' => CreateListing::route('/create'),
            'view' => ViewListing::route('/{record}'),
            'edit' => EditListing::route('/{record}/edit'),
        ];
    }
}
