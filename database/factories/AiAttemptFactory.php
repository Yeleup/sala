<?php

namespace Database\Factories;

use App\Enums\AiAttemptStatus;
use App\Enums\AiCostStatus;
use App\Models\AiAttempt;
use App\Models\AiOperation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiAttempt>
 */
class AiAttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_operation_id' => AiOperation::factory(),
            'status' => AiAttemptStatus::Succeeded,
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'invocation_id' => (string) Str::uuid(),
            'input_tokens' => fake()->numberBetween(200, 2000),
            'output_tokens' => fake()->numberBetween(50, 500),
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'reasoning_tokens' => 0,
            'latency_ms' => fake()->numberBetween(300, 4000),
            'prompt' => fake()->sentence(),
            'response' => fake()->sentence(),
            'pricing_snapshot' => ['input' => 1.0, 'output' => 4.0],
            'estimated_cost_usd' => fake()->randomFloat(6, 0.0001, 0.05),
            'cost_status' => AiCostStatus::Estimated,
        ];
    }

    public function failed(string $error = 'Provider error'): static
    {
        return $this->state(fn (): array => [
            'status' => AiAttemptStatus::Failed,
            'error' => $error,
            'response' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'estimated_cost_usd' => null,
            'cost_status' => AiCostStatus::Unknown,
        ]);
    }

    public function unknownCost(): static
    {
        return $this->state(fn (): array => [
            'model' => 'experimental-model',
            'pricing_snapshot' => null,
            'estimated_cost_usd' => null,
            'cost_status' => AiCostStatus::Unknown,
        ]);
    }
}
