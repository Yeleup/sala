<?php

namespace App\Enums;

/**
 * What a «Условие» scenario block checks. Conditions read domain state at
 * the moment the run reaches the block — a reply that arrives days later
 * is checked against the current state, not the state at send time.
 */
enum ScenarioCondition: string
{
    /** The run's customer request still awaits the supplier's decision. */
    case RequestPending = 'request_pending';

    /** The run's listing is still published. */
    case ListingPublished = 'listing_published';

    /** The contact's 24-hour session window is open. */
    case WindowOpen = 'window_open';

    /**
     * Conditions about a subject entity are meaningful only in scenarios
     * whose trigger supplies that subject.
     */
    public function allowedIn(BotScenarioTrigger $trigger): bool
    {
        return match ($this) {
            self::RequestPending => $trigger === BotScenarioTrigger::NewCustomerRequest,
            self::ListingPublished => $trigger === BotScenarioTrigger::ListingExpiring,
            self::WindowOpen => $trigger->isRunBased(),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::RequestPending => 'Заявка ещё ожидает ответа',
            self::ListingPublished => 'Объявление опубликовано',
            self::WindowOpen => 'Окно WhatsApp открыто',
        };
    }
}
