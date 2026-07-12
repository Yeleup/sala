<?php

namespace App\Http\Controllers;

use App\Jobs\ApplyDereuDeliveryStatus;
use App\Jobs\ApplyDereuTemplateStatus;
use App\Jobs\ProcessDereuWebhookEvent;
use App\Models\DereuWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DereuWebhookController extends Controller
{
    /**
     * Store a webhook event forwarded by Dereu and acknowledge it quickly.
     *
     * Inbound messages are deduplicated by wamid (one message can be delivered
     * more than once with different event_ids); every other event type is
     * deduplicated by event_id (one wamid legitimately produces sent,
     * delivered, and read status events).
     */
    public function __invoke(Request $request): Response
    {
        $data = $request->json()->all();

        $event = (string) ($data['event'] ?? '');
        $eventId = (string) ($data['event_id'] ?? '');
        $wamid = $data['wamid'] ?? null;

        if ($event === '' || $eventId === '') {
            abort(422, 'Missing event or event_id.');
        }

        $dedupeKey = $event === 'message_received' && filled($wamid)
            ? 'wamid:'.$wamid
            : 'event:'.$eventId;

        $storedEvent = DereuWebhookEvent::query()->createOrFirst(
            ['dedupe_key' => $dedupeKey],
            [
                'event' => $event,
                'event_id' => $eventId,
                'company_id' => $data['company_id'] ?? null,
                'phone_number_id' => $data['phone_number_id'] ?? null,
                'wamid' => is_string($wamid) ? $wamid : null,
                'payload' => $data,
            ],
        );

        if ($storedEvent->wasRecentlyCreated && $event === 'message_received') {
            ProcessDereuWebhookEvent::dispatch($storedEvent);
        }

        if ($storedEvent->wasRecentlyCreated && $event === 'template_status_update') {
            ApplyDereuTemplateStatus::dispatch($storedEvent);
        }

        if ($storedEvent->wasRecentlyCreated
            && in_array($event, ['message_sent', 'message_delivered', 'message_read', 'message_failed'], true)) {
            ApplyDereuDeliveryStatus::dispatch($storedEvent);
        }

        return response()->noContent();
    }
}
