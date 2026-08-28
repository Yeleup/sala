<?php

namespace App\Models;

use App\Enums\AiCostStatus;
use App\Enums\ChannelDirection;
use App\Enums\ChannelMessageStatus;
use Database\Factories\ChannelMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One WhatsApp message in the channel journal — inbound or outbound, with
 * the raw payload and the delivery lifecycle. Outbound rows are matched
 * to Dereu delivery webhooks by dereu_message_id. Template messages carry
 * a tariff snapshot fixed at send time (cost_status = null means the row
 * predates cost accounting); session messages have no cost columns filled.
 */
#[Fillable([
    'contact_id', 'direction', 'type', 'text', 'payload', 'wamid',
    'dereu_message_id', 'status', 'failure_reason', 'sent_at', 'delivered_at', 'read_at',
    'whatsapp_template_id', 'estimated_cost_usd', 'cost_status', 'pricing_snapshot',
    'template_fallback', 'template_fallback_resent_at',
])]
class ChannelMessage extends Model
{
    /** @use HasFactory<ChannelMessageFactory> */
    use HasFactory;

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<WhatsappTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsappTemplate::class, 'whatsapp_template_id');
    }

    /**
     * AI operations triggered by this (inbound) message.
     *
     * @return HasMany<AiOperation, $this>
     */
    public function aiOperations(): HasMany
    {
        return $this->hasMany(AiOperation::class);
    }

    /**
     * The machine ids of every button the message carried: the reply
     * buttons of an interactive message and the quick-reply payloads of a
     * template message. They are what ties an undelivered message back to
     * what it was asking about — the answer would have carried the same
     * ids.
     *
     * @return Collection<int, string>
     */
    public function buttonIds(): Collection
    {
        $payload = $this->payload ?? [];

        $interactive = collect($payload['action']['buttons'] ?? [])
            ->map(fn (mixed $button): mixed => is_array($button) ? ($button['reply']['id'] ?? null) : null);

        $template = collect($payload['components'] ?? [])
            ->filter(fn (mixed $component): bool => is_array($component) && ($component['type'] ?? null) === 'button')
            ->flatMap(fn (array $component): Collection => collect($component['parameters'] ?? [])
                ->map(fn (mixed $parameter): mixed => is_array($parameter) ? ($parameter['payload'] ?? null) : null));

        return $interactive->merge($template)->filter(fn (mixed $id): bool => is_string($id))->values();
    }

    /**
     * Apply a delivery webhook to the journal entry. Statuses only move
     * forward: Dereu retries and out-of-order deliveries must not turn a
     * «прочитано» back into «доставлено».
     */
    public function applyDeliveryStatus(ChannelMessageStatus $status, ?string $wamid = null, ?string $failureReason = null): void
    {
        if (filled($wamid) && blank($this->wamid)) {
            $this->wamid = $wamid;
        }

        match ($status) {
            ChannelMessageStatus::Sent => $this->sent_at ??= now(),
            ChannelMessageStatus::Delivered => $this->delivered_at ??= now(),
            ChannelMessageStatus::Read => $this->read_at ??= now(),
            default => null,
        };

        if ($status === ChannelMessageStatus::Failed) {
            $this->failure_reason = $failureReason ?? $this->failure_reason;
        }

        if ($status->rank() > $this->status->rank()) {
            $this->status = $status;
        }

        $this->save();
    }

    /**
     * @return array{direction: class-string<ChannelDirection>, status: class-string<ChannelMessageStatus>, payload: 'array', sent_at: 'datetime', delivered_at: 'datetime', read_at: 'datetime', estimated_cost_usd: 'decimal:6', cost_status: class-string<AiCostStatus>, pricing_snapshot: 'array'}
     */
    protected function casts(): array
    {
        return [
            'direction' => ChannelDirection::class,
            'status' => ChannelMessageStatus::class,
            'payload' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'estimated_cost_usd' => 'decimal:6',
            'cost_status' => AiCostStatus::class,
            'pricing_snapshot' => 'array',
            'template_fallback' => 'array',
            'template_fallback_resent_at' => 'datetime',
        ];
    }
}
