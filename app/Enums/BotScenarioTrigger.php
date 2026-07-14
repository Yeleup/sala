<?php

namespace App\Enums;

/**
 * What starts a scenario. The main dialog reacts to the contact's own
 * inbound messages; the other triggers launch an isolated scenario run
 * per event (see ScenarioRun) without touching the main dialog.
 */
enum BotScenarioTrigger: string
{
    case InboundMessage = 'inbound_message';
    case NewCustomerRequest = 'new_customer_request';
    case ListingExpiring = 'listing_expiring';

    /**
     * Run-based scenarios execute as isolated ScenarioRuns; the main
     * dialog is driven by BotSession instead.
     */
    public function isRunBased(): bool
    {
        return $this !== self::InboundMessage;
    }

    public function label(): string
    {
        return match ($this) {
            self::InboundMessage => 'Входящее сообщение',
            self::NewCustomerRequest => 'Новая заявка',
            self::ListingExpiring => 'Истекает объявление',
        };
    }
}
