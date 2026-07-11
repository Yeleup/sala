<?php

namespace Database\Factories;

use App\Enums\CustomerRequestStatus;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerRequest>
 */
class CustomerRequestFactory extends Factory
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
            'listing_id' => Listing::factory()->published(),
            'query_text' => fake()->randomElement([
                'Нужен кран 25 тонн в Шымкенте на завтра',
                'Ищу экскаватор, копать траншею в Алматы',
                'Требуется сварщик на объект в Астане',
            ]),
            'status' => CustomerRequestStatus::Pending,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => ['status' => CustomerRequestStatus::Accepted]);
    }

    public function declined(): static
    {
        return $this->state(fn (): array => ['status' => CustomerRequestStatus::Declined]);
    }
}
