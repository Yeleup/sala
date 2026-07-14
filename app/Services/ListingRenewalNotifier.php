<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\WhatsappTemplate;
use App\Services\Bot\NotificationReplyHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the 30-day relevance poll («Оно ещё актуально?» with the
 * [Да, актуально]/[Нет, в архив] buttons): a session message while the
 * supplier's window is open, the listing_renewal template otherwise.
 */
class ListingRenewalNotifier
{
    public const string BUTTON_YES_TITLE = 'Да, актуально';

    public const string BUTTON_NO_TITLE = 'Нет, в архив';

    public function __construct(private readonly DereuMessenger $messenger) {}

    /**
     * True when the poll went out; false leaves the listing unpolled so
     * the next daily cycle retries (e.g. the template is not approved yet).
     */
    public function sendPoll(Listing $listing): bool
    {
        $supplier = $listing->supplier;
        $category = $listing->category?->name ?: 'без категории';
        $yesId = NotificationReplyHandler::renewalYesId($listing);
        $noId = NotificationReplyHandler::renewalNoId($listing);

        try {
            if ($supplier->hasOpenSessionWindow()) {
                $this->messenger->sendButtons(
                    $supplier,
                    sprintf('Ваше объявление «%s» скоро перестанет показываться в поиске. Оно ещё актуально?', $category),
                    [
                        ['id' => $yesId, 'title' => self::BUTTON_YES_TITLE],
                        ['id' => $noId, 'title' => self::BUTTON_NO_TITLE],
                    ],
                );

                return true;
            }

            $template = WhatsappTemplate::query()
                ->approved()
                ->where('name', WhatsappTemplateLibrary::LISTING_RENEWAL)
                ->first();

            if ($template === null) {
                Log::warning('No approved listing_renewal template — the renewal poll is postponed.', [
                    'listing_id' => $listing->id,
                ]);

                return false;
            }

            $this->messenger->sendTemplate($supplier, $template, [$category], [$yesId, $noId]);

            return true;
        } catch (Throwable $e) {
            Log::warning('Failed to send the renewal poll.', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
