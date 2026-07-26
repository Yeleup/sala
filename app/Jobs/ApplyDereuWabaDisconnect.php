<?php

namespace App\Jobs;

use App\Enums\DereuCompanyStatus;
use App\Models\DereuCompany;
use App\Models\DereuWebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Applies a `waba_disconnected` webhook from Dereu: the number's owner moved
 * it to another integration (owner-consented transfer), so sending and
 * receiving through this company are already dead upstream — mirror that by
 * deactivating the local binding, exactly like the manual disconnect action.
 */
class ApplyDereuWabaDisconnect implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public DereuWebhookEvent $event) {}

    public function handle(): void
    {
        $event = $this->event->fresh();

        if ($event === null || $event->processed_at !== null || $event->event !== 'waba_disconnected') {
            return;
        }

        $company = DereuCompany::current();

        if ($company === null || blank($event->company_id) || $event->company_id !== $company->dereu_company_id) {
            Log::warning('Dereu waba_disconnected event belongs to an unknown company, skipping.', [
                'event_id' => $event->event_id,
                'company_id' => $event->company_id,
            ]);
            $event->update(['processed_at' => now()]);

            return;
        }

        $company->update([
            'status' => DereuCompanyStatus::Deactivated,
            'api_key' => null,
        ]);

        Log::warning('Dereu reported the WhatsApp number as disconnected; the local binding was deactivated.', [
            'event_id' => $event->event_id,
            'company_id' => $event->company_id,
            'phone_number_id' => $event->phone_number_id,
            'reason' => $event->payload['reason'] ?? null,
        ]);

        $event->update(['processed_at' => now()]);
    }
}
