<?php

namespace Database\Factories;

use App\Models\DereuWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DereuWebhookEvent>
 */
class DereuWebhookEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $wamid = 'wamid.'.Str::random(24);

        return [
            'event' => 'message_received',
            'event_id' => (string) Str::ulid(),
            'dedupe_key' => 'wamid:'.$wamid,
            'company_id' => 'co_'.Str::lower(Str::random(6)),
            'phone_number_id' => $this->faker->numerify('##########'),
            'wamid' => $wamid,
            'payload' => [
                'event' => 'message_received',
                'type' => 'text',
                'text' => $this->faker->sentence(),
                'payload' => ['body' => $this->faker->sentence()],
            ],
        ];
    }

    public function deliveryStatus(string $event = 'message_delivered'): static
    {
        return $this->state(fn (array $attributes): array => [
            'event' => $event,
            'dedupe_key' => 'event:'.$attributes['event_id'],
            'payload' => ['event' => $event],
        ]);
    }
}
