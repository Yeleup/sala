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
        $eventId = (string) Str::ulid();
        $wamid = 'wamid.'.Str::random(16);

        return [
            'event' => 'message_received',
            'event_id' => $eventId,
            'dedupe_key' => 'wamid:'.$wamid,
            'company_id' => 'co_'.Str::lower(Str::random(8)),
            'phone_number_id' => (string) fake()->numerify('##########'),
            'wamid' => $wamid,
            'payload' => [
                'event' => 'message_received',
                'event_id' => $eventId,
                'type' => 'text',
                'payload' => ['body' => fake()->sentence()],
            ],
        ];
    }
}
