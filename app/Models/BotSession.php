<?php

namespace App\Models;

use Database\Factories\BotSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A contact's position inside the published scenario: the waiting block
 * the contact must answer next. current_node_id is null between dialogs —
 * the next inbound message starts a new dialog from the Start block.
 *
 * updated_at doubles as the last dialog activity; after 24 hours of
 * silence the dialog is considered finished (mirrors the WhatsApp
 * session window).
 */
#[Fillable(['contact_id', 'bot_scenario_id', 'scenario_version', 'current_node_id', 'current_node_fingerprint', 'state'])]
class BotSession extends Model
{
    /** @use HasFactory<BotSessionFactory> */
    use HasFactory;

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return array{state: 'array'}
     */
    protected function casts(): array
    {
        return [
            'state' => 'array',
        ];
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
}
