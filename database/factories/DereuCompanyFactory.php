<?php

namespace Database\Factories;

use App\Enums\DereuCompanyStatus;
use App\Models\DereuCompany;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DereuCompany>
 */
class DereuCompanyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_id' => 'org_'.Str::lower(Str::random(8)),
            'name' => fake()->company(),
            'dereu_company_id' => 'co_'.Str::lower(Str::random(8)),
            'waba_id' => (string) fake()->unique()->numerify('##########'),
            'phone_number_id' => (string) fake()->unique()->numerify('##########'),
            'api_key' => 'dereu_'.Str::random(24),
            'status' => DereuCompanyStatus::Connected,
            'connected_at' => now(),
        ];
    }

    public function deactivated(): static
    {
        return $this->state(fn (): array => [
            'status' => DereuCompanyStatus::Deactivated,
            'api_key' => null,
        ]);
    }

    public function withoutApiKey(): static
    {
        return $this->state(fn (): array => ['api_key' => null]);
    }
}
