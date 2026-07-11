<?php

namespace Database\Factories;

use App\Models\BotScenario;
use App\Models\BotSession;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotSession>
 */
class BotSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'bot_scenario_id' => BotScenario::factory()->published(),
            'scenario_version' => 1,
            'current_node_id' => null,
        ];
    }

    public function waitingAt(string $nodeId): static
    {
        return $this->state(['current_node_id' => $nodeId]);
    }

    public function expired(): static
    {
        return $this->state([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
    }
}
