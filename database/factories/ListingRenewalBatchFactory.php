<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\ListingRenewalBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListingRenewalBatch>
 */
class ListingRenewalBatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
        ];
    }
}
