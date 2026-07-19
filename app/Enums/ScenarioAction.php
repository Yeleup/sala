<?php

namespace App\Enums;

/**
 * What a «Действие» scenario block performs. Actions delegate to the
 * domain services/models, which keep their own invariants (status guards,
 * final decisions, Meta limits) — the scenario only orchestrates.
 */
enum ScenarioAction: string
{
    case AcceptRequest = 'accept_request';
    case DeclineRequest = 'decline_request';
    case RenewListing = 'renew_listing';
    case ArchiveListing = 'archive_listing';

    /** Sends the personal signed CTA link into the supplier web portal. */
    case SendCabinetCta = 'send_cabinet_cta';

    /** Tells the customer the outcome of their request (по статусу заявки). */
    case NotifyCustomer = 'notify_customer';

    public function allowedIn(BotScenarioTrigger $trigger): bool
    {
        return match ($this) {
            self::AcceptRequest,
            self::DeclineRequest,
            self::NotifyCustomer => $trigger === BotScenarioTrigger::NewCustomerRequest,
            self::RenewListing,
            self::ArchiveListing => $trigger === BotScenarioTrigger::ListingExpiring,
            self::SendCabinetCta => $trigger->isRunBased(),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::AcceptRequest => 'Принять заявку',
            self::DeclineRequest => 'Отклонить заявку',
            self::RenewListing => 'Продлить объявление на 30 дней',
            self::ArchiveListing => 'Архивировать объявление',
            self::SendCabinetCta => 'Отправить CTA-ссылку на кабинет',
            self::NotifyCustomer => 'Уведомить заказчика об исходе',
        };
    }

    /**
     * Действия, которые домен может отвергнуть по предусловию статуса
     * (заявка уже решена, объявление не опубликовано). Только у них
     * есть выход «Не выполнено»; best-effort действия всегда идут
     * по «Продолжить».
     */
    public function hasPrecondition(): bool
    {
        return match ($this) {
            self::AcceptRequest,
            self::DeclineRequest,
            self::RenewListing,
            self::ArchiveListing => true,
            self::SendCabinetCta,
            self::NotifyCustomer => false,
        };
    }

    /** Подпись выхода «не выполнено» — конкретный факт вместо термина «предусловие». */
    public function skippedLabel(): ?string
    {
        return match ($this) {
            self::AcceptRequest,
            self::DeclineRequest => 'Заявка уже решена',
            self::RenewListing,
            self::ArchiveListing => 'Объявление уже в архиве',
            self::SendCabinetCta,
            self::NotifyCustomer => null,
        };
    }
}
