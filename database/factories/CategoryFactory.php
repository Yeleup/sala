<?php

namespace Database\Factories;

use App\Enums\ListingType;
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
            'type' => ListingType::Equipment,
        ];
    }

    public function service(): static
    {
        return $this->state(fn (): array => ['type' => ListingType::Service]);
    }
}
