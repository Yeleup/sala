<?php

namespace Database\Factories;

use App\Enums\ListingMediaType;
use App\Models\Listing;
use App\Models\ListingMedia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListingMedia>
 */
class ListingMediaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'listing_id' => Listing::factory(),
            'type' => ListingMediaType::Photo,
            'disk' => 'public',
            'path' => 'listing-media/'.fake()->uuid().'.jpg',
            'transcription' => null,
        ];
    }

    public function audio(): static
    {
        return $this->state(fn (): array => [
            'type' => ListingMediaType::Audio,
            'path' => 'listing-media/'.fake()->uuid().'.ogg',
            'transcription' => 'Сдаю в аренду автокран 25 тонн, нахожусь в Шымкенте, цена договорная.',
        ]);
    }
}
