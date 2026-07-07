<?php

namespace Database\Factories;

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
            'name' => $this->faker->company(),
            'dereu_company_id' => 'co_'.Str::lower(Str::random(8)),
            'api_key' => 'dereu_'.Str::random(24),
            'status' => DereuCompany::STATUS_PROVISIONED,
        ];
    }

    public function connected(): static
    {
        return $this->state(fn (): array => [
            'status' => DereuCompany::STATUS_CONNECTED,
            'waba_id' => (string) $this->faker->numerify('##########'),
            'phone_number_id' => (string) $this->faker->numerify('##########'),
            'display_phone_number' => '7701'.$this->faker->numerify('#######'),
        ]);
    }

    public function withoutApiKey(): static
    {
        return $this->state(fn (): array => ['api_key' => null]);
    }
}
