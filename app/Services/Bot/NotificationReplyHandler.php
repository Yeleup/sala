<?php

namespace App\Services\Bot;

use App\Enums\CustomerRequestStatus;
use App\Enums\ListingStatus;
use App\Exceptions\SessionWindowClosed;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Services\Ai\CtaLinkBuilder;
use App\Services\DereuMessenger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Handles button replies to proactive notifications — messages the bot
 * sent outside the scenario flow (the customer request notification, the
 * 30-day renewal poll, the moderation verdict). Such a reply can arrive
 * days later, whatever step of the scenario the contact is on, so the
 * engine offers each inbound message here before scenario processing.
 */
class NotificationReplyHandler
{
    private const string REQUEST_ACCEPT_PREFIX = 'request_accept:';

    private const string REQUEST_DECLINE_PREFIX = 'request_decline:';

    private const string RENEWAL_YES_PREFIX = 'renewal_yes:';

    private const string RENEWAL_NO_PREFIX = 'renewal_no:';

    private const string LISTING_OPEN_PREFIX = 'listing_open:';

    /** The «Обновить объявления» button of the fleet update broadcast. */
    public const string MY_LISTINGS_REPLY = 'my_listings';

    public function __construct(
        private readonly DereuMessenger $messenger,
        private readonly CtaLinkBuilder $links,
    ) {}

    public static function requestAcceptId(CustomerRequest $request): string
    {
        return self::REQUEST_ACCEPT_PREFIX.$request->id;
    }

    public static function requestDeclineId(CustomerRequest $request): string
    {
        return self::REQUEST_DECLINE_PREFIX.$request->id;
    }

    public static function renewalYesId(Listing $listing): string
    {
        return self::RENEWAL_YES_PREFIX.$listing->id;
    }

    public static function renewalNoId(Listing $listing): string
    {
        return self::RENEWAL_NO_PREFIX.$listing->id;
    }

    public static function listingOpenId(Listing $listing): string
    {
        return self::LISTING_OPEN_PREFIX.$listing->id;
    }

    /**
     * True when the message was a notification reply and is fully handled —
     * the engine must not run the scenario for it.
     */
    public function handle(Contact $contact, InboundMessage $message): bool
    {
        $replyId = (string) $message->replyId;

        if (str_starts_with($replyId, self::REQUEST_ACCEPT_PREFIX)) {
            return $this->handleRequestReply($contact, $replyId, accept: true);
        }

        if (str_starts_with($replyId, self::REQUEST_DECLINE_PREFIX)) {
            return $this->handleRequestReply($contact, $replyId, accept: false);
        }

        if (str_starts_with($replyId, self::RENEWAL_YES_PREFIX)) {
            return $this->handleRenewalReply($contact, $replyId, stillRelevant: true);
        }

        if (str_starts_with($replyId, self::RENEWAL_NO_PREFIX)) {
            return $this->handleRenewalReply($contact, $replyId, stillRelevant: false);
        }

        if (str_starts_with($replyId, self::LISTING_OPEN_PREFIX)) {
            return $this->handleListingOpenReply($contact, $replyId);
        }

        if ($replyId === self::MY_LISTINGS_REPLY) {
            $this->messenger->sendCtaUrl(
                $contact,
                'Откройте кабинет, чтобы обновить или снять с публикации свои объявления.',
                'Открыть кабинет',
                $this->links->myListingsUrl($contact),
            );

            return true;
        }

        return false;
    }

    /**
     * The «Открыть объявление» button of the moderation verdict template.
     * The tap reopens the 24-hour window, so the signed CTA link (which a
     * paid template cannot carry — it may be read after the link expires)
     * goes out fresh right here, whatever the listing's status is by now.
     */
    protected function handleListingOpenReply(Contact $contact, string $replyId): bool
    {
        $listing = Listing::query()->find((int) Str::of($replyId)->afterLast(':')->value());

        // Deleting a listing is a routine admin flow (spam cleanup), so the
        // button may legitimately outlive it — answer instead of ignoring.
        if ($listing === null) {
            $this->messenger->sendText(
                $contact,
                'Этого объявления уже нет. Чтобы разместить его снова, создайте новое объявление.',
            );

            return true;
        }

        if ($listing->contact_id !== $contact->id) {
            return true;
        }

        $this->messenger->sendCtaUrl(
            $contact,
            sprintf('Откройте объявление «%s» — внутри его статус и подробности.', $listing->displayName() ?: 'без названия'),
            'Открыть объявление',
            $this->links->editUrl($listing),
        );

        return true;
    }

    /**
     * The [Да, актуально]/[Нет, в архив] answer to the 30-day renewal
     * poll. A late answer after the auto-archive cannot revive the
     * listing — returns from the archive are not part of the MVP.
     */
    protected function handleRenewalReply(Contact $contact, string $replyId, bool $stillRelevant): bool
    {
        $listing = Listing::query()->find((int) Str::of($replyId)->afterLast(':')->value());

        if ($listing === null || $listing->contact_id !== $contact->id) {
            return true;
        }

        if ($listing->status !== ListingStatus::Published) {
            $this->messenger->sendText(
                $contact,
                'Это объявление уже в архиве. Чтобы разместить его снова, создайте новое объявление.',
            );

            return true;
        }

        if ($stillRelevant) {
            $listing->renew();
            $this->messenger->sendText($contact, sprintf(
                'Продлили: объявление «%s» будет показываться ещё %d дней.',
                $listing->displayName() ?: 'без названия',
                Listing::LIFETIME_DAYS,
            ));

            return true;
        }

        $listing->archive();
        $this->messenger->sendText($contact, 'Перенесли объявление в архив — оно больше не показывается в поиске.');

        return true;
    }

    protected function handleRequestReply(Contact $contact, string $replyId, bool $accept): bool
    {
        $request = CustomerRequest::query()->find((int) Str::of($replyId)->afterLast(':')->value());

        // A foreign or vanished request: swallow the click silently — the
        // button was rendered by us, so this only happens on stale data.
        if ($request === null || $request->listing->contact_id !== $contact->id) {
            return true;
        }

        if ($request->status !== CustomerRequestStatus::Pending) {
            $this->messenger->sendText($contact, 'Ответ по этой заявке уже зафиксирован — решение не меняется.');

            return true;
        }

        $accept ? $request->accept() : $request->decline();

        $this->messenger->sendText($contact, $accept
            ? 'Отлично! Мы сообщим заказчику, что вы готовы взять заказ.'
            : 'Понятно, заявку отклонили. Объявление продолжает показываться в поиске.');

        $this->notifyCustomer($request, $accept);

        return true;
    }

    /**
     * Best effort: the customer's own 24-hour window may already be closed
     * (the supplier answered days later) — then the outcome stays visible
     * to the operator only. An MVP trade-off, no paid template for it.
     */
    protected function notifyCustomer(CustomerRequest $request, bool $accepted): void
    {
        try {
            $this->messenger->sendText($request->customer, $accepted
                ? sprintf(
                    'Поставщик согласился по вашей заявке («%s»). Свяжитесь с ним: +%s',
                    $request->listing->displayName() ?: 'объявление',
                    ltrim($request->listing->supplier->phone, '+'),
                )
                : 'К сожалению, поставщик отказался по вашей заявке. Напишите нам — подберём другие варианты.');
        } catch (SessionWindowClosed) {
            Log::info('Customer window closed — the request outcome was not delivered.', [
                'customer_request_id' => $request->id,
            ]);
        }
    }
}
