<?php

namespace App\Models;

use Database\Factories\BotSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * A contact's position inside the published scenario: the waiting block
 * the contact must answer next. current_node_id is null between dialogs —
 * the next inbound message starts a new dialog from the Start block.
 *
 * updated_at doubles as the last dialog activity; after 24 hours of
 * silence the dialog is considered finished (mirrors the WhatsApp
 * session window).
 */
#[Fillable(['contact_id', 'bot_scenario_id', 'scenario_version', 'current_node_id', 'current_node_fingerprint', 'state', 'last_dialog_ended_at', 'paused_state'])]
class BotSession extends Model
{
    /** @use HasFactory<BotSessionFactory> */
    use HasFactory;

    /** How long a paused-questionnaire snapshot stays resumable before pausedState() treats it as gone. */
    public const int PAUSED_STATE_TTL_HOURS = 48;

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return array{state: 'array', last_dialog_ended_at: 'datetime', paused_state: 'array'}
     */
    protected function casts(): array
    {
        return [
            'state' => 'array',
            'last_dialog_ended_at' => 'datetime',
            'paused_state' => 'array',
        ];
    }

    /**
     * Whether this contact has already reached at least one waiting step
     * with the bot — set the moment the first menu or AI block is shown,
     * not only when a dialog fully ends, so the greeting shows once. The
     * signal the Start block's «Повторное обращение» output branches on
     * to skip the first-time greeting.
     */
    public function hasCompletedDialog(): bool
    {
        return $this->last_dialog_ended_at !== null;
    }

    /** @return BelongsTo<BotScenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(BotScenario::class, 'bot_scenario_id');
    }

    public function isExpired(): bool
    {
        return $this->updated_at === null || $this->updated_at->isBefore(now()->subDay());
    }

    /**
     * The interrupted-questionnaire snapshot the AI navigator saved before
     * routing the contact away, validated and TTL-checked — never the raw
     * column. A missing field, a wrong shape or a saved_at that has aged
     * past PAUSED_STATE_TTL_HOURS all read the same as "nothing to
     * resume". Read-only: a stale or malformed snapshot is left in the
     * column as-is, not cleared.
     *
     * @return array{node_id: string, fingerprint: string, state: array<string, mixed>, saved_at: string}|null
     */
    public function pausedState(): ?array
    {
        $snapshot = $this->paused_state;

        if (! is_array($snapshot)) {
            return null;
        }

        $nodeId = $snapshot['node_id'] ?? null;
        $fingerprint = $snapshot['fingerprint'] ?? null;
        $state = $snapshot['state'] ?? null;
        $savedAt = $snapshot['saved_at'] ?? null;

        if (! is_string($nodeId) || ! filled($nodeId)) {
            return null;
        }

        if (! is_string($fingerprint) || ! filled($fingerprint)) {
            return null;
        }

        if (! is_array($state)) {
            return null;
        }

        if (! is_string($savedAt) || ! filled($savedAt)) {
            return null;
        }

        try {
            $savedAtDate = Carbon::parse($savedAt);
        } catch (Throwable) {
            return null;
        }

        return $savedAtDate->isAfter(now()->subHours(self::PAUSED_STATE_TTL_HOURS)) ? $snapshot : null;
    }
}
