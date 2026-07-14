<?php

namespace App\Models;

use App\Enums\ScenarioRunStatus;
use App\Services\Bot\ScenarioDefinition;
use Database\Factories\ScenarioRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One isolated execution of a run-based scenario, created per proactive
 * send (a request notification, a renewal poll, one broadcast recipient).
 * It never touches the contact's main dialog: the same supplier may await
 * answers about several requests and listings at once.
 *
 * The run is pinned to the published version that sent its messages —
 * a button clicked days after a republication still lands in the branch
 * that produced it. Buttons carry flow:{token}:{option} payloads, so a
 * reply is matched by the opaque token, never by the button text.
 */
#[Fillable(['token', 'bot_scenario_id', 'scenario_version', 'contact_id', 'subject_type', 'subject_id', 'status', 'current_node_id', 'timeout_at'])]
class ScenarioRun extends Model
{
    /** @use HasFactory<ScenarioRunFactory> */
    use HasFactory;

    public static function generateToken(): string
    {
        return Str::lower(Str::random(32));
    }

    /** @return BelongsTo<BotScenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(BotScenario::class, 'bot_scenario_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isActive(): bool
    {
        return $this->status === ScenarioRunStatus::Active;
    }

    /**
     * The immutable graph snapshot this run follows.
     */
    public function scenarioDefinition(): ?ScenarioDefinition
    {
        return BotScenarioVersion::query()
            ->where('bot_scenario_id', $this->bot_scenario_id)
            ->where('version', $this->scenario_version)
            ->first()
            ?->scenarioDefinition();
    }

    /**
     * @return array{status: class-string<ScenarioRunStatus>, timeout_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'status' => ScenarioRunStatus::class,
            'timeout_at' => 'datetime',
        ];
    }
}
