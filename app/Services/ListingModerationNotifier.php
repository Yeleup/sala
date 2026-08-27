<?php

namespace App\Services;

use App\Enums\ModerationNoticeOutcome;
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
 *
 * A message into an open window is free, so it always goes out. Outside
 * the window the notification costs money, and the caller decides whether
 * to spend it ($paidTemplateAllowed). The decision is only asked about a
 * window that is already closed: a window that closes between the check
 * and the send still falls back to the template, because the choice made
 * then was «notify», and only the price of it changed.
 */
class ListingModerationNotifier
{
    public const string BUTTON_OPEN_TITLE = 'Открыть объявление';

    public function __construct(
        private readonly DereuMessenger $messenger,
        private readonly CtaLinkBuilder $links,
    ) {}

    /**
     * The moderation action shows the delivery state to the operator, so
     * a notification skipped on purpose reads differently from a failed one.
     */
    public function notifyApproved(Listing $listing, bool $paidTemplateAllowed = true): ModerationNoticeOutcome
    {
        return $this->notify(
            $listing,
            sprintf(
                'Ваше объявление «%s» прошло модерацию и опубликовано — оно будет показываться в поиске %d дней.',
                $this->name($listing),
                Listing::LIFETIME_DAYS,
            ),
            WhatsappTemplateLibrary::LISTING_APPROVED,
            $paidTemplateAllowed,
        );
    }

    public function notifyRejected(Listing $listing, bool $paidTemplateAllowed = true): ModerationNoticeOutcome
    {
        return $this->notify(
            $listing,
            sprintf(
                'Ваше объявление «%s» не прошло модерацию. Откройте его, чтобы узнать причину, исправить и отправить повторно.',
                $this->name($listing),
            ),
            WhatsappTemplateLibrary::LISTING_REJECTED,
            $paidTemplateAllowed,
        );
    }

    /**
     * Whether a paid notification of the approval is deliverable at all —
     * without an approved template there is nothing to offer the operator.
     */
    public function canSendApprovalTemplate(): bool
    {
        return $this->template(WhatsappTemplateLibrary::LISTING_APPROVED) !== null;
    }

    protected function notify(Listing $listing, string $text, string $templateName, bool $paidTemplateAllowed): ModerationNoticeOutcome
    {
        $supplier = $listing->supplier;

        try {
            if ($supplier->hasOpenSessionWindow()) {
                try {
                    // The template rides along as plan B: the window can
                    // also close after Dereu accepts the message, and the
                    // async rejection then re-sends through the template.
                    $fallbackTemplate = $this->template($templateName);

                    $this->messenger->sendCtaUrl(
                        $supplier,
                        $text,
                        self::BUTTON_OPEN_TITLE,
                        $this->links->editUrl($listing),
                        fallback: $fallbackTemplate === null ? null : new TemplateFallback(
                            $fallbackTemplate,
                            [$this->name($listing)],
                            [NotificationReplyHandler::listingOpenId($listing)],
                        ),
                    );

                    return ModerationNoticeOutcome::Delivered;
                } catch (SessionWindowClosed) {
                    // The window expired between the check and the send —
                    // the paid template below still delivers.
                }
            } elseif (! $paidTemplateAllowed) {
                return ModerationNoticeOutcome::Skipped;
            }

            $template = $this->template($templateName);

            if ($template === null) {
                Log::warning("No approved {$templateName} template — the moderation verdict was not delivered.", [
                    'listing_id' => $listing->id,
                ]);

                return ModerationNoticeOutcome::Failed;
            }

            $this->messenger->sendTemplate(
                $supplier,
                $template,
                [$this->name($listing)],
                [NotificationReplyHandler::listingOpenId($listing)],
            );

            return ModerationNoticeOutcome::Delivered;
        } catch (Throwable $e) {
            Log::error('Failed to deliver the moderation verdict to the supplier.', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);

            return ModerationNoticeOutcome::Failed;
        }
    }

    protected function template(string $name): ?WhatsappTemplate
    {
        return WhatsappTemplate::query()
            ->approved()
            ->where('name', $name)
            ->first();
    }

    protected function name(Listing $listing): string
    {
        return $listing->displayName() ?: 'без названия';
    }
}
