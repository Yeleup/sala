<?php

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone' => fake()->unique()->numerify('7705#######'),
            'profile_name' => fake()->firstName(),
            'last_inbound_at' => null,
        ];
    }

    public function withOpenSessionWindow(): static
    {
        return $this->state(fn (): array => ['last_inbound_at' => now()->subHour()]);
    }

    public function withClosedSessionWindow(): static
    {
        return $this->state(fn (): array => ['last_inbound_at' => now()->subDays(2)]);
    }
}
