<?php

namespace App\Filament\Resources\Listings;

use App\Enums\ListingStatus;
use App\Enums\ModerationNoticeOutcome;
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
use Filament\Forms\Components\Toggle;
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

    /**
     * The verdict itself is one click; who pays for telling the supplier
     * about it is the only question the modal asks — and only when the
     * answer costs money. Into an open 24-hour window the notification is
     * free, so it goes out without asking. Outside the window it is a paid
     * template, and the operator decides: approve silently or notify.
     */
    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Одобрить')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Listing $record): bool => $record->status === ListingStatus::PendingModeration)
            ->requiresConfirmation()
            ->modalHeading('Опубликовать объявление?')
            ->modalDescription(fn (Listing $record): string => self::approvalNotes($record))
            ->schema(fn (Listing $record): array => self::paidNoticeIsOperatorsChoice($record) ? [
                Toggle::make('notify_supplier')
                    ->label('Уведомить поставщика платным шаблоном')
                    ->helperText('Шаблонное сообщение WhatsApp — платное. Без него объявление публикуется тихо.')
                    ->default(false),
            ] : [])
            ->action(function (Listing $record, array $data): void {
                $record->approve(self::currentOperator());

                // No toggle in the modal means the operator was never asked:
                // either the window is open (the send is free anyway) or
                // there is no approved template — let the notifier try and
                // report honestly why nothing reached the supplier.
                $outcome = app(ListingModerationNotifier::class)->notifyApproved(
                    $record,
                    paidTemplateAllowed: (bool) ($data['notify_supplier'] ?? true),
                );

                Notification::make()
                    ->title('Объявление опубликовано')
                    ->body(match ($outcome) {
                        ModerationNoticeOutcome::Delivered => 'Поставщику отправлено уведомление в WhatsApp.',
                        ModerationNoticeOutcome::Skipped => 'Уведомление поставщику не отправлялось — статус он увидит в веб-кабинете.',
                        ModerationNoticeOutcome::Failed => 'Уведомить поставщика в WhatsApp не удалось — статус он увидит в веб-кабинете.',
                    })
                    ->success()
                    ->send();
            });
    }

    /**
     * The paid notification is offered only when it is both the only way
     * to reach the supplier (his window is closed) and actually sendable
     * (the verdict template is approved by Meta).
     */
    private static function paidNoticeIsOperatorsChoice(Listing $record): bool
    {
        return ! ($record->supplier?->hasOpenSessionWindow() ?? false)
            && app(ListingModerationNotifier::class)->canSendApprovalTemplate();
    }

    private static function approvalNotes(Listing $record): string
    {
        $notes = ['Объявление попадёт в поиск на '.Listing::LIFETIME_DAYS.' дней.'];

        if ($record->supplier?->hasOpenSessionWindow()) {
            $notes[] = 'Поставщик недавно писал — уведомление уйдёт ему бесплатным сообщением.';
        } elseif (self::paidNoticeIsOperatorsChoice($record)) {
            $notes[] = '24-часовое окно переписки с поставщиком закрыто: бесплатное сообщение ему не доставить.';
        } else {
            $notes[] = 'Окно переписки с поставщиком закрыто, а утверждённого шаблона «Объявление опубликовано» нет — уведомить его не получится, статус он увидит в веб-кабинете.';
        }

        return implode(' ', $notes);
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

                $outcome = app(ListingModerationNotifier::class)->notifyRejected($record);

                Notification::make()
                    ->title('Объявление отклонено')
                    ->body($outcome === ModerationNoticeOutcome::Delivered
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
            ->modalDescription('Объявление уйдёт из поиска заказчиков. Вернуть его туда можно действием «Вернуть в поиск» — здесь же или кнопкой самого поставщика в веб-кабинете.')
            ->action(function (Listing $record): void {
                $record->archive();

                Notification::make()
                    ->title('Объявление в архиве')
                    ->success()
                    ->send();
            });
    }

    /**
     * The way back from the archive, for a supplier who will not press the
     * web-cabinet button himself: the one who never wrote to the bot has no
     * CTA link to press, and the one who let the renewal poll expire is
     * usually the one who calls instead. Without this the operator's only
     * answer to «верните объявление обратно» was to retype it from scratch.
     *
     * The transition is the supplier's own «Вернуть в поиск», not a second
     * publication: the listing goes back exactly as it already stood in the
     * search, so neither moderation nor field completeness is re-checked
     * (see Listing::restoreFromArchive). No WhatsApp notification either —
     * the operator restores a listing having just agreed it by phone.
     */
    public static function restoreAction(): Action
    {
        return Action::make('restore')
            ->label('Вернуть в поиск')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('success')
            ->visible(fn (Listing $record): bool => $record->status === ListingStatus::Archived)
            ->requiresConfirmation()
            ->modalHeading('Вернуть объявление в поиск?')
            ->modalDescription(fn (Listing $record): string => self::restorationNotes($record))
            ->action(function (Listing $record): void {
                $record->restoreFromArchive();

                Notification::make()
                    ->title('Объявление снова в поиске')
                    ->body('Объявление показывается заказчикам ещё '.Listing::LIFETIME_DAYS.' дней.')
                    ->success()
                    ->send();
            });
    }

    /**
     * The same warning publicationNotes() gives, and for the same reason:
     * a supplier who has never written to the bot will not confirm the
     * renewal poll, so in 30 days the listing archives itself again — by
     * the very path it took the first time. Without the warning the
     * operator would answer the same phone call every month, never
     * learning that the way out is «Продлить» by hand.
     */
    private static function restorationNotes(Listing $record): string
    {
        $notes = ['Объявление вернётся в поиск на '.Listing::LIFETIME_DAYS.' дней в том же виде, в каком уже было там: повторную модерацию оно не проходит. Уведомление поставщику не отправляется.'];

        if (! $record->supplier?->hasEverWritten()) {
            $notes[] = 'Поставщик ни разу не писал боту, поэтому опрос актуальности он не подтвердит — через '.Listing::LIFETIME_DAYS.' дней объявление снова уйдёт в архив; продлевайте его вручную или попросите поставщика написать боту.';
        }

        return implode(' ', $notes);
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
