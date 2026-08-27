<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\ListingRenewalBatch;
use App\Models\WhatsappTemplate;
use App\Services\Bot\NotificationReplyHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the 30-day relevance poll («Оно ещё актуально?» with the
 * [Да, актуально]/[Нет, в архив] buttons): a session message while the
 * supplier's window is open, the listing_renewal template otherwise.
 *
 * A supplier whose publications expire together is asked once about the
 * whole set instead of once per listing — twelve expiring listings cost
 * one paid template, not twelve.
 */
class ListingRenewalNotifier
{
    public const string BUTTON_YES_TITLE = 'Да, актуально';

    public const string BUTTON_NO_TITLE = 'Нет, в архив';

    public const string BATCH_ALL_YES_TITLE = 'Все актуальны';

    public const string BATCH_PICK_TITLE = 'Разобрать по одному';

    public const string BATCH_ALL_NO_TITLE = 'Все в архив';

    public function __construct(private readonly DereuMessenger $messenger) {}

    /**
     * True when the poll went out; false leaves the listing unpolled so
     * the next daily cycle retries (e.g. the template is not approved yet).
     */
    public function sendPoll(Listing $listing): bool
    {
        $supplier = $listing->supplier;
        $name = $listing->displayName() ?: 'без названия';
        $yesId = NotificationReplyHandler::renewalYesId($listing);
        $noId = NotificationReplyHandler::renewalNoId($listing);

        try {
            if ($supplier->hasOpenSessionWindow()) {
                // Plan B: the window can close after Dereu accepts the
                // message — the async rejection then re-sends through the
                // template with the same button payloads.
                $fallbackTemplate = WhatsappTemplate::query()
                    ->approved()
                    ->where('name', WhatsappTemplateLibrary::LISTING_RENEWAL)
                    ->first();

                $this->messenger->sendButtons(
                    $supplier,
                    sprintf('Ваше объявление «%s» скоро перестанет показываться в поиске. Оно ещё актуально?', $name),
                    [
                        ['id' => $yesId, 'title' => self::BUTTON_YES_TITLE],
                        ['id' => $noId, 'title' => self::BUTTON_NO_TITLE],
                    ],
                    fallback: $fallbackTemplate === null ? null : new TemplateFallback($fallbackTemplate, [$name], [$yesId, $noId]),
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

            $this->messenger->sendTemplate($supplier, $template, [$name], [$yesId, $noId]);

            return true;
        } catch (Throwable $e) {
            Log::error('Failed to send the renewal poll.', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The batch variant: one question about every listing of the batch.
     * True when the poll went out; false leaves the whole batch unpolled
     * so the next daily cycle retries it.
     */
    public function sendBatchPoll(ListingRenewalBatch $batch): bool
    {
        $supplier = $batch->supplier;
        $named = $batch->namedListing()?->displayName() ?: 'без названия';
        $rest = ListingRenewalBatch::restPhrase($batch->listings()->count() - 1);
        $buttons = [
            ['id' => NotificationReplyHandler::batchRenewAllId($batch), 'title' => self::BATCH_ALL_YES_TITLE],
            ['id' => NotificationReplyHandler::batchPickId($batch), 'title' => self::BATCH_PICK_TITLE],
            ['id' => NotificationReplyHandler::batchArchiveAllId($batch), 'title' => self::BATCH_ALL_NO_TITLE],
        ];

        try {
            $template = WhatsappTemplate::query()
                ->approved()
                ->where('name', WhatsappTemplateLibrary::SEVERAL_LISTINGS_RENEWAL)
                ->first();

            if ($supplier->hasOpenSessionWindow()) {
                // Plan B: the window can close after Dereu accepts the
                // message — the async rejection then re-sends through the
                // template with the same button payloads.
                $this->messenger->sendButtons(
                    $supplier,
                    sprintf('Ваше объявление «%s» и ещё %s скоро перестанут показываться в поиске. Они ещё актуальны?', $named, $rest),
                    $buttons,
                    fallback: $template === null
                        ? null
                        : new TemplateFallback($template, [$named, $rest], array_column($buttons, 'id')),
                );

                return true;
            }

            if ($template === null) {
                Log::warning('No approved several_listings_renewal template — the batch renewal poll is postponed.', [
                    'listing_renewal_batch_id' => $batch->id,
                ]);

                return false;
            }

            $this->messenger->sendTemplate($supplier, $template, [$named, $rest], array_column($buttons, 'id'));

            return true;
        } catch (Throwable $e) {
            Log::error('Failed to send the batch renewal poll.', [
                'listing_renewal_batch_id' => $batch->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
