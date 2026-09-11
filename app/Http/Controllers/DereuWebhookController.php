<?php

namespace App\Http\Controllers;

use App\Models\DereuWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class DereuWebhookController extends Controller
{
    /**
     * Store a webhook event forwarded by Dereu and acknowledge it quickly.
     *
     * Inbound messages are deduplicated by wamid (one message can be delivered
     * more than once with different event_ids); every other event type is
     * deduplicated by event_id (one wamid legitimately produces sent,
     * delivered, and read status events).
     *
     * A message the operator sent from the WhatsApp Business app is the
     * same kind of thing as an inbound one — one message, possibly
     * delivered twice — but its id lives inside the echo rather than at
     * the top of the envelope, so it is dug out for the key.
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

        $echoId = $data['payload']['message_echoes'][0]['id'] ?? null;

        $dedupeKey = match (true) {
            $event === 'message_received' && filled($wamid) => 'wamid:'.$wamid,
            $event === 'business_app_message_echo' && is_string($echoId) && filled($echoId) => 'wamid:'.$echoId,
            default => 'event:'.$eventId,
        };

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

        // The event→job mapping lives on the model, shared with the
        // redispatch sweeper: an event queued here is exactly an event the
        // sweeper can re-queue if this dispatch is lost to a queue hiccup.
        if (! $storedEvent->wasRecentlyCreated) {
            return response()->noContent();
        }

        if (($job = $storedEvent->jobClass()) !== null) {
            $job::dispatch($storedEvent);

            return response()->noContent();
        }

        // An event nobody handles used to be stored and forgotten in
        // silence. That is how 141 operator messages accumulated unseen
        // for a month and a half — so an unmapped event now says so.
        Log::warning('Dereu webhook event has no handler; it is stored but nothing will read it.', [
            'event' => $event,
            'event_id' => $eventId,
        ]);

        return response()->noContent();
    }
}
