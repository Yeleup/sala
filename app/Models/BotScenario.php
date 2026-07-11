<?php

namespace App\Models;

use App\Services\Bot\ScenarioDefinition;
use Database\Factories\BotScenarioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A branching dialog scenario built in the no-code constructor.
 *
 * The definition is a graph: blocks (nodes) connected by transitions
 * (edges) — see ScenarioDefinition for the shape. Admins edit
 * draft_definition; contacts are always driven by published_definition,
 * which changes atomically on publication (published_version grows).
 *
 * The bot has a single main scenario (docs/modules/bot-constructor.md).
 */
#[Fillable(['name', 'draft_definition', 'published_definition', 'published_version', 'published_at'])]
class BotScenario extends Model
{
    /** @use HasFactory<BotScenarioFactory> */
    use HasFactory;

    public static function main(): ?self
    {
        return static::query()->orderBy('id')->first();
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
     * version up on their next message (soft update in the bot engine).
     */
    public function publishDraft(): void
    {
        $this->forceFill([
            'published_definition' => $this->draft_definition,
            'published_version' => $this->published_version + 1,
            'published_at' => now(),
        ])->save();
    }

    public function hasUnpublishedChanges(): bool
    {
        return $this->draft_definition !== $this->published_definition;
    }

    /**
     * @return array{draft_definition: 'array', published_definition: 'array', published_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'draft_definition' => 'array',
            'published_definition' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
