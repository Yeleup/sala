<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\DereuCompany;
use App\Models\DereuWebhookEvent;
use App\Services\Bot\BotEngine;
use App\Services\Bot\InboundMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Processes a stored inbound message event: updates the contact (and the
 * 24-hour session window base) and lets the bot engine reply. Delivery
 * status events stay stored as-is for later modules.
 */
class ProcessDereuWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public DereuWebhookEvent $event) {}

    /**
     * Messages of one contact are processed strictly one at a time, so
     * concurrent workers cannot race on the same dialog session.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        $contactKey = (string) ($this->event->payload['from'] ?? $this->event->id);

        return [
            (new WithoutOverlapping('bot-contact:'.$contactKey))
                ->releaseAfter(10)
                ->expireAfter(120),
        ];
    }

    public function handle(BotEngine $engine): void
    {
        $event = $this->event->fresh();

        if ($event === null || $event->processed_at !== null || $event->event !== 'message_received') {
            return;
        }

        $expectedCompanyId = DereuCompany::current()?->dereu_company_id;

        if (filled($expectedCompanyId) && filled($event->company_id) && $event->company_id !== $expectedCompanyId) {
            Log::warning('Dereu webhook event belongs to an unknown company, skipping.', [
                'event_id' => $event->event_id,
                'company_id' => $event->company_id,
            ]);
            $event->update(['processed_at' => now()]);

            return;
        }

        $phone = (string) ($event->payload['from'] ?? '');

        if ($phone === '') {
            $event->update(['processed_at' => now()]);

            return;
        }

        $contact = $this->syncContact($event, $phone);

        $engine->handle($contact, InboundMessage::fromWebhookEvent($event));

        $event->update(['processed_at' => now()]);
    }

    private function syncContact(DereuWebhookEvent $event, string $phone): Contact
    {
        $contact = Contact::query()->firstOrNew(['phone' => $phone]);

        $receivedAt = isset($event->payload['timestamp'])
            ? Carbon::createFromTimestamp((int) $event->payload['timestamp'])
            : now();

        // Redeliveries can arrive out of order — never move the window back.
        if ($contact->last_inbound_at === null || $contact->last_inbound_at->isBefore($receivedAt)) {
            $contact->last_inbound_at = $receivedAt;
        }

        if (filled($event->payload['profile_name'] ?? null)) {
            $contact->profile_name = $event->payload['profile_name'];
        }

        $contact->save();

        return $contact;
    }
}
