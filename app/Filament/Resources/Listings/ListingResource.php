<?php

namespace App\Filament\Resources\Listings;

use App\Enums\ListingStatus;
use App\Filament\Clusters\Marketplace\MarketplaceCluster;
use App\Filament\Resources\Listings\Pages\CreateListing;
use App\Filament\Resources\Listings\Pages\EditListing;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Filament\Resources\Listings\Schemas\ListingForm;
use App\Filament\Resources\Listings\Tables\ListingsTable;
use App\Models\Listing;
use App\Models\User;
use App\Services\ListingModerationNotifier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

/**
 * Listings in the admin: the moderation queue (approve/reject) plus full
 * CRUD for the operator — creating a listing on a supplier's behalf,
 * editing its business fields and deleting (including bulk delete).
 * Status transitions stay behind the dedicated lifecycle actions.
 * A listing has no separate read-only page: opening it lands on the
 * edit form, which doubles as the moderation screen.
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
        return ['title', 'person_name', 'category.name'];
    }

    public static function form(Schema $schema): Schema
    {
        return ListingForm::configure($schema);
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
                $record->approve(self::currentOperator());

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
                $record->reject($data['rejection_reason'], self::currentOperator());

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
     * The listing as the customer sees it, rendered by the customer's own
     * page: moderating by the form fields alone hides what actually
     * reaches the catalog — how the photos crop, where the description
     * breaks, whether the title reads like an offer. Opens in a new tab so
     * the form the operator is filling in is not lost.
     */
    public static function previewAction(): Action
    {
        return Action::make('preview')
            ->label('Посмотреть объявление')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->url(fn (Listing $record): string => route('moderation.listings.preview', $record))
            ->openUrlInNewTab();
    }

    /**
     * Publication of a listing the operator typed himself: he is both its
     * author and the verdict on it, so routing his own text through the
     * moderation queue would only add clicks. Two things replace that
     * barrier — the completeness of the business fields (an incomplete
     * listing never reaches customer search anyway) and the recorded
     * author of the publication.
     *
     * Unlike a verdict on a listing collected in chat, this sends the
     * supplier no WhatsApp notification: an operator-typed listing is
     * agreed with him by phone.
     */
    public static function publishAction(): Action
    {
        return Action::make('publish')
            ->label('Опубликовать')
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->color('success')
            ->visible(fn (Listing $record): bool => in_array($record->status, [ListingStatus::Draft, ListingStatus::Rejected], true))
            ->disabled(fn (Listing $record): bool => ! $record->isReadyForPublication())
            ->tooltip(fn (Listing $record): ?string => $record->isReadyForPublication()
                ? null
                : 'Не хватает для публикации: '.implode(', ', $record->missingForPublication()))
            ->requiresConfirmation()
            ->modalHeading('Опубликовать объявление?')
            ->modalDescription(fn (Listing $record): string => self::publicationNotes($record))
            ->action(function (Listing $record): void {
                $record->publish(self::currentOperator());

                Notification::make()
                    ->title('Объявление опубликовано')
                    ->body('Объявление в поиске на '.Listing::LIFETIME_DAYS.' дней. Уведомление поставщику не отправлялось.')
                    ->success()
                    ->send();
            });
    }

    /**
     * A supplier who has never written to the bot cannot be reached for
     * free, and the 30-day renewal poll will not get a confirmation out
     * of him — the operator has to know that before he publishes, not on
     * the day the listing silently archives itself.
     */
    private static function publicationNotes(Listing $record): string
    {
        $notes = ['Объявление сразу попадёт в поиск на '.Listing::LIFETIME_DAYS.' дней, минуя очередь модерации. Поставщику уведомление не уходит.'];

        if (! $record->supplier?->hasEverWritten()) {
            $notes[] = 'Поставщик ни разу не писал боту, поэтому опрос актуальности он не подтвердит — продлите объявление вручную или попросите его написать боту.';
        }

        return implode(' ', $notes);
    }

    /**
     * Taking a listing off publication the regular way. Without it the
     * operator's only answer to «кран продал, снимите» is an irreversible
     * delete, which also wipes the listing's media and the customer
     * requests on it — the record of demand.
     */
    public static function archiveAction(): Action
    {
        return Action::make('archive')
            ->label('В архив')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->visible(fn (Listing $record): bool => $record->status === ListingStatus::Published)
            ->requiresConfirmation()
            ->modalHeading('Снять объявление с публикации?')
            ->modalDescription('Объявление уйдёт из поиска заказчиков. Возврат из архива не предусмотрен — поставщику придётся завести новое объявление.')
            ->action(function (Listing $record): void {
                $record->archive();

                Notification::make()
                    ->title('Объявление в архиве')
                    ->success()
                    ->send();
            });
    }

    /**
     * The supplier confirmed by phone that his offer still stands — the
     * same prolongation the [Да, актуально] button of the renewal poll
     * gives, for suppliers the poll cannot reach.
     */
    public static function renewAction(): Action
    {
        return Action::make('renew')
            ->label('Продлить')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (Listing $record): bool => $record->status === ListingStatus::Published)
            ->requiresConfirmation()
            ->modalHeading('Продлить объявление на '.Listing::LIFETIME_DAYS.' дней?')
            ->modalDescription('Подтвердите, только если поставщик сказал, что предложение ещё актуально.')
            ->action(function (Listing $record): void {
                $record->renew();

                Notification::make()
                    ->title('Объявление продлено на '.Listing::LIFETIME_DAYS.' дней')
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
     * The operator behind the action, for the verdict trail. The panel is
     * open to any authenticated user, so this is a record of who acted —
     * not a permission check.
     */
    private static function currentOperator(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListListings::route('/'),
            'create' => CreateListing::route('/create'),
            'edit' => EditListing::route('/{record}/edit'),
        ];
    }
}
