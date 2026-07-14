<?php

namespace App\Models;

use App\Enums\BotScenarioTrigger;
use App\Services\Bot\ScenarioDefinition;
use Database\Factories\BotScenarioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A branching dialog scenario built in the no-code constructor.
 *
 * The definition is a graph: blocks (nodes) connected by transitions
 * (edges) — see ScenarioDefinition for the shape. Admins edit
 * draft_definition; contacts are always driven by published_definition,
 * which changes atomically on publication (published_version grows) —
 * each publication is also snapshotted as an immutable BotScenarioVersion
 * for the runs already out there.
 *
 * The bot has one main dialog scenario (trigger inbound_message) and any
 * number of run-based scenarios: auto-scenarios reacting to system events
 * and broadcast scenarios launched by the operator
 * (docs/modules/bot-constructor.md).
 */
#[Fillable(['name', 'trigger', 'draft_definition', 'published_definition', 'published_version', 'published_at'])]
class BotScenario extends Model
{
    /** @use HasFactory<BotScenarioFactory> */
    use HasFactory;

    /** The single main dialog scenario driven by inbound messages. */
    public static function main(): ?self
    {
        return static::query()
            ->where('trigger', BotScenarioTrigger::InboundMessage)
            ->orderBy('id')
            ->first();
    }

    /**
     * The published scenario a system event launches. With several
     * published scenarios on one trigger the oldest wins — publication
     * validation warns about such duplicates.
     */
    public static function publishedForTrigger(BotScenarioTrigger $trigger): ?self
    {
        return static::query()
            ->where('trigger', $trigger)
            ->where('published_version', '>', 0)
            ->orderBy('id')
            ->first();
    }

    /** @return HasMany<BotScenarioVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(BotScenarioVersion::class);
    }

    /** @return HasMany<ScenarioRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(ScenarioRun::class);
    }

    public function isPublished(): bool
    {
        return $this->published_version > 0 && filled($this->published_definition);
    }

    public function publishedDefinition(): ?ScenarioDefinition
    {
        return $this->isPublished() ? new ScenarioDefinition($this->published_definition) : null;
    }

    /**
     * Atomically apply the draft to contacts: active sessions pick the new
     * version up on their next message (soft update in the bot engine),
     * while runs already launched stay pinned to their snapshots.
     */
    public function publishDraft(): void
    {
        DB::transaction(function (): void {
            $this->forceFill([
                'published_definition' => $this->draft_definition,
                'published_version' => $this->published_version + 1,
                'published_at' => now(),
            ])->save();

            $this->versions()->create([
                'version' => $this->published_version,
                'definition' => $this->published_definition,
                'published_at' => $this->published_at,
            ]);
        });
    }

    public function hasUnpublishedChanges(): bool
    {
        return $this->draft_definition !== $this->published_definition;
    }

    /**
     * @return array{trigger: class-string<BotScenarioTrigger>, draft_definition: 'array', published_definition: 'array', published_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'trigger' => BotScenarioTrigger::class,
            'draft_definition' => 'array',
            'published_definition' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
