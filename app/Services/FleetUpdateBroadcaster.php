<?php

namespace App\Services;

use App\Enums\ListingStatus;
use App\Models\Contact;
use App\Models\WhatsappTemplate;
use App\Services\Bot\NotificationReplyHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The operator's mass «актуализируйте парк» broadcast to every supplier
 * with a published listing. Inside the supplier's 24-hour window it goes
 * as a free session message; outside — as the marketing
 * fleet_status_update template (Dereu may refuse individual recipients
 * without a marketing opt-in; such failures are counted, not fatal).
 * The «Обновить объявления» button replies with a personal CTA link to
 * the supplier web portal.
 */
class FleetUpdateBroadcaster
{
    public const string BUTTON_TITLE = 'Обновить объявления';

    private const string MESSAGE_TEXT = 'Здравствуйте! Мы обновляем каталог техники и услуг. Нажмите кнопку ниже — и мы пришлём ссылку для обновления ваших объявлений.';

    public function __construct(private readonly DereuMessenger $messenger) {}

    /**
     * @return array{sent: int, failed: int}
     */
    public function broadcast(): array
    {
        $template = WhatsappTemplate::query()
            ->approved()
            ->where('name', WhatsappTemplateLibrary::FLEET_STATUS_UPDATE)
            ->first();

        $sent = 0;
        $failed = 0;

        Contact::query()
            ->whereHas('listings', fn ($query) => $query->where('status', ListingStatus::Published))
            ->get()
            ->each(function (Contact $supplier) use ($template, &$sent, &$failed): void {
                try {
                    if ($supplier->hasOpenSessionWindow()) {
                        $this->messenger->sendButtons($supplier, self::MESSAGE_TEXT, [
                            ['id' => NotificationReplyHandler::MY_LISTINGS_REPLY, 'title' => self::BUTTON_TITLE],
                        ]);
                    } elseif ($template !== null) {
                        $this->messenger->sendTemplate($supplier, $template, [], [NotificationReplyHandler::MY_LISTINGS_REPLY]);
                    } else {
                        throw new \RuntimeException('No approved fleet_status_update template.');
                    }

                    $sent++;
                } catch (Throwable $e) {
                    $failed++;
                    Log::warning('Fleet update broadcast failed for a supplier.', [
                        'contact_id' => $supplier->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        return ['sent' => $sent, 'failed' => $failed];
    }
}
