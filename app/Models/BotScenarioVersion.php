<?php

namespace App\Models;

use App\Services\Bot\ScenarioDefinition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable snapshot of one publication of a scenario. Runs (and any
 * late button click they receive) resolve their graph from here, so a
 * republication never rewires messages that are already out.
 */
#[Fillable(['bot_scenario_id', 'version', 'definition', 'published_at'])]
class BotScenarioVersion extends Model
{
    /** @return BelongsTo<BotScenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(BotScenario::class, 'bot_scenario_id');
    }

    public function scenarioDefinition(): ScenarioDefinition
    {
        return new ScenarioDefinition($this->definition ?? []);
    }

    /**
     * @return array{definition: 'array', published_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
