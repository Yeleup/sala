<?php

namespace App\Enums;

/**
 * Data sources for the {{n}} placeholders of a «WhatsApp-сообщение»
 * block: the node maps each placeholder to one of these keys, and the
 * run resolves them from its subject (the request or the listing) and
 * the contact at send time.
 */
enum ScenarioVariable: string
{
    case ListingCategory = 'listing.category';
    case ListingDescription = 'listing.description';
    case ListingLocation = 'listing.location';
    case ListingPrice = 'listing.price';
    case RequestQuery = 'request.query';
    case ContactName = 'contact.name';
    case ContactPhone = 'contact.phone';

    public function allowedIn(BotScenarioTrigger $trigger): bool
    {
        return match ($this) {
            self::ListingCategory,
            self::ListingDescription,
            self::ListingLocation,
            self::ListingPrice => in_array($trigger, [BotScenarioTrigger::NewCustomerRequest, BotScenarioTrigger::ListingExpiring], true),
            self::RequestQuery => $trigger === BotScenarioTrigger::NewCustomerRequest,
            self::ContactName,
            self::ContactPhone => $trigger->isRunBased(),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ListingCategory => 'Объявление: категория',
            self::ListingDescription => 'Объявление: описание',
            self::ListingLocation => 'Объявление: локация',
            self::ListingPrice => 'Объявление: цена/тариф',
            self::RequestQuery => 'Заявка: текст запроса',
            self::ContactName => 'Контакт: имя',
            self::ContactPhone => 'Контакт: телефон',
        };
    }
}
