<?php

namespace Database\Factories;

use App\Enums\ScenarioRunStatus;
use App\Models\BotScenario;
use App\Models\Contact;
use App\Models\ScenarioRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScenarioRun>
 */
class ScenarioRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token' => ScenarioRun::generateToken(),
            'bot_scenario_id' => BotScenario::factory()->published(),
            'scenario_version' => 1,
            'contact_id' => Contact::factory(),
            'status' => ScenarioRunStatus::Active,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => ['status' => ScenarioRunStatus::Completed]);
    }
}
