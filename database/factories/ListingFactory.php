<?php

namespace Database\Factories;

use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Listing;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'type' => ListingType::Equipment,
            // Null by default: listings drafted before the title field
            // existed have none, and every display falls back to the
            // category name — tests opt into a title explicitly.
            'title' => null,
            'category_id' => Category::factory(),
            'description' => fake()->randomElement([
                'Автокран 25 тонн, стрела 28 м, работаем по городу и области.',
                'Экскаватор-погрузчик, копаем траншеи и котлованы.',
                'Самосвал 20 тонн, доставка сыпучих материалов.',
            ]),
            'location_id' => Location::factory(),
            'location_detail' => null,
            'price' => (fake()->numberBetween(5, 50) * 1000).' тг/ч',
            'status' => ListingStatus::Draft,
            'rejection_reason' => null,
            'expires_at' => null,
        ];
    }

    public function service(): static
    {
        return $this->state(fn (): array => [
            'type' => ListingType::Service,
            'category_id' => Category::factory()->service(),
            'description' => 'Бригада с допусками, выезд на объект в день обращения.',
        ]);
    }

    public function pendingModeration(): static
    {
        return $this->state(fn (): array => ['status' => ListingStatus::PendingModeration]);
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => ListingStatus::Published,
            'expires_at' => now()->addDays(Listing::LIFETIME_DAYS),
        ]);
    }

    /**
     * Published, but the 30-day cycle has run out: invisible to search.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => ListingStatus::Published,
            'expires_at' => now()->subDay(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => ListingStatus::Rejected,
            'rejection_reason' => 'Не указана цена — добавьте тариф.',
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => ListingStatus::Archived]);
    }
}
