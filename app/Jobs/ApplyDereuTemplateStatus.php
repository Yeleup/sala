<?php

namespace App\Jobs;

use App\Enums\WhatsappTemplateStatus;
use App\Models\DereuCompany;
use App\Models\DereuWebhookEvent;
use App\Models\WhatsappTemplate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Applies a `template_status_update` webhook from Dereu: Meta finished (or
 * re-ran) moderation of a template — mirror the verdict into the local
 * registry. Unknown templates are ignored; the next sync will pick them up.
 */
class ApplyDereuTemplateStatus implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public DereuWebhookEvent $event) {}

    public function handle(): void
    {
        $event = $this->event->fresh();

        if ($event === null || $event->processed_at !== null || $event->event !== 'template_status_update') {
            return;
        }

        $expectedCompanyId = DereuCompany::current()?->dereu_company_id;

        if (filled($expectedCompanyId) && filled($event->company_id) && $event->company_id !== $expectedCompanyId) {
            Log::warning('Dereu template status event belongs to an unknown company, skipping.', [
                'event_id' => $event->event_id,
                'company_id' => $event->company_id,
            ]);
            $event->update(['processed_at' => now()]);

            return;
        }

        /** @var array<string, mixed> $payload */
        $payload = $event->payload['payload'] ?? [];

        $name = (string) ($payload['name'] ?? '');
        $language = (string) ($payload['language'] ?? '');
        $status = WhatsappTemplateStatus::tryFrom((string) ($payload['status'] ?? ''));

        if ($name !== '' && $language !== '' && $status !== null) {
            WhatsappTemplate::query()
                ->where('name', $name)
                ->where('language', $language)
                ->first()
                ?->update([
                    'status' => $status,
                    'rejection_reason' => $status === WhatsappTemplateStatus::Rejected
                        ? ($payload['reason'] ?? null)
                        : null,
                ]);
        }

        $event->update(['processed_at' => now()]);
    }
}
