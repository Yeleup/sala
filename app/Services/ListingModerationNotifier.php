<?php

namespace App\Services;

use App\Exceptions\SessionWindowClosed;
use App\Models\Listing;
use App\Models\WhatsappTemplate;
use App\Services\Ai\CtaLinkBuilder;
use App\Services\Bot\NotificationReplyHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the supplier the moderation verdict with a personal CTA link to
 * the listing: an interactive cta_url message while the supplier's
 * 24-hour window is open, the listing_approved/listing_rejected template
 * otherwise. Signed links live 7 days, so a paid template (read whenever)
 * cannot carry one — its «Открыть объявление» button reply reopens the
 * window and the fresh link goes out then. The rejection reason itself is
 * never sent to WhatsApp — the supplier sees it only on the linked page.
 * A delivery problem never blocks moderation.
 */
class ListingModerationNotifier
{
    public const string BUTTON_OPEN_TITLE = 'Открыть объявление';

    public function __construct(
        private readonly DereuMessenger $messenger,
        private readonly CtaLinkBuilder $links,
    ) {}

    /**
     * True when the verdict message went out — the moderation action
     * shows the delivery state to the operator.
     */
    public function notifyApproved(Listing $listing): bool
    {
        return $this->notify(
            $listing,
            sprintf(
                'Ваше объявление «%s» прошло модерацию и опубликовано — оно будет показываться в поиске %d дней.',
                $this->name($listing),
                Listing::LIFETIME_DAYS,
            ),
            WhatsappTemplateLibrary::LISTING_APPROVED,
        );
    }

    public function notifyRejected(Listing $listing): bool
    {
        return $this->notify(
            $listing,
            sprintf(
                'Ваше объявление «%s» не прошло модерацию. Откройте его, чтобы узнать причину, исправить и отправить повторно.',
                $this->name($listing),
            ),
            WhatsappTemplateLibrary::LISTING_REJECTED,
        );
    }

    protected function notify(Listing $listing, string $text, string $templateName): bool
    {
        $supplier = $listing->supplier;

        try {
            if ($supplier->hasOpenSessionWindow()) {
                try {
                    $this->messenger->sendCtaUrl(
                        $supplier,
                        $text,
                        self::BUTTON_OPEN_TITLE,
                        $this->links->editUrl($listing),
                    );

                    return true;
                } catch (SessionWindowClosed) {
                    // The window expired between the check and the send —
                    // the paid template below still delivers.
                }
            }

            $template = WhatsappTemplate::query()
                ->approved()
                ->where('name', $templateName)
                ->first();

            if ($template === null) {
                Log::warning("No approved {$templateName} template — the moderation verdict was not delivered.", [
                    'listing_id' => $listing->id,
                ]);

                return false;
            }

            $this->messenger->sendTemplate(
                $supplier,
                $template,
                [$this->name($listing)],
                [NotificationReplyHandler::listingOpenId($listing)],
            );

            return true;
        } catch (Throwable $e) {
            Log::warning('Failed to deliver the moderation verdict to the supplier.', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function name(Listing $listing): string
    {
        return $listing->displayName() ?: 'без названия';
    }
}
