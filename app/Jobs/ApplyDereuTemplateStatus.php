<?php

namespace App\Jobs;

use App\Enums\WhatsappTemplateStatus;
use App\Models\DereuCompany;
use App\Models\DereuWebhookEvent;
use App\Models\WhatsappTemplate;
use App\Services\WhatsappTemplateAlerts;
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

    public function handle(WhatsappTemplateAlerts $alerts): void
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
        // Meta пишет статусы в верхнем регистре; сравнение без учёта
        // регистра, иначе весь блок молча превращается в no-op и шаблон
        // навсегда остаётся «На модерации Meta».
        $status = WhatsappTemplateStatus::tryFrom(strtolower(trim((string) ($payload['status'] ?? ''))));

        if ($status === null) {
            Log::warning('Unrecognised template status in the Dereu webhook.', [
                'event_id' => $event->event_id,
                'status' => $payload['status'] ?? null,
            ]);
        }

        if ($name !== '' && $language !== '' && $status !== null) {
            $template = WhatsappTemplate::query()
                ->where('name', $name)
                ->where('language', $language)
                ->first();

            $becameRejected = $template !== null
                && $status === WhatsappTemplateStatus::Rejected
                && $template->status !== WhatsappTemplateStatus::Rejected;

            $template?->update([
                'status' => $status,
                'rejection_reason' => $status === WhatsappTemplateStatus::Rejected
                    ? ($payload['reason'] ?? null)
                    : null,
            ]);

            // Only the transition alarms: a redelivered verdict about an
            // already rejected template adds nothing but noise.
            if ($becameRejected) {
                $alerts->templateRejected($template);
            }
        }

        $event->update(['processed_at' => now()]);
    }
}
