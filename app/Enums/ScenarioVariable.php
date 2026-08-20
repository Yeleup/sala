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
    case ListingTitle = 'listing.title';
    case ListingCategory = 'listing.category';
    case ListingDescription = 'listing.description';
    case ListingLocation = 'listing.location';
    case ListingPrice = 'listing.price';

    /** Название одного из объявлений пачки — «Автокран 25 т». */
    case ExpiringListingsFirst = 'listings.expiring_first';

    /** Сколько ещё объявлений в пачке, кроме названного, — «11 объявлений». */
    case ExpiringListingsRest = 'listings.expiring_rest';
    case RequestQuery = 'request.query';
    case RequestCustomer = 'request.customer';
    case ContactName = 'contact.name';
    case ContactPhone = 'contact.phone';

    public function allowedIn(BotScenarioTrigger $trigger): bool
    {
        return match ($this) {
            self::ListingTitle,
            self::ListingCategory,
            self::ListingDescription,
            self::ListingLocation,
            self::ListingPrice => in_array($trigger, [BotScenarioTrigger::NewCustomerRequest, BotScenarioTrigger::ListingExpiring], true),
            self::ExpiringListingsFirst,
            self::ExpiringListingsRest => $trigger === BotScenarioTrigger::ListingsExpiringBatch,
            self::RequestQuery,
            self::RequestCustomer => $trigger === BotScenarioTrigger::NewCustomerRequest,
            self::ContactName,
            self::ContactPhone => $trigger->isRunBased(),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ListingTitle => 'Объявление: название',
            self::ListingCategory => 'Объявление: категория',
            self::ListingDescription => 'Объявление: описание',
            self::ListingLocation => 'Объявление: локация',
            self::ListingPrice => 'Объявление: цена/тариф',
            self::ExpiringListingsFirst => 'Объявления: название одного из пачки',
            self::ExpiringListingsRest => 'Объявления: сколько ещё в пачке',
            self::RequestQuery => 'Заявка: текст запроса',
            self::RequestCustomer => 'Заявка: заказчик (имя, телефон)',
            self::ContactName => 'Получатель: имя',
            self::ContactPhone => 'Получатель: телефон',
        };
    }
}
