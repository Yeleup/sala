<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'approved_at' => now(),
        ];
    }

    /**
     * A category the AI added that no approved listing carries yet.
     */
    public function unapproved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'approved_at' => null,
        ]);
    }
}
