<?php

namespace Database\Factories;

use App\Enums\ChannelDirection;
use App\Enums\ChannelMessageStatus;
use App\Models\ChannelMessage;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChannelMessage>
 */
class ChannelMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = fake()->sentence();

        return [
            'contact_id' => Contact::factory(),
            'direction' => ChannelDirection::Inbound,
            'type' => 'text',
            'text' => $text,
            'payload' => ['body' => $text],
            'wamid' => 'wamid.'.Str::random(24),
            'dereu_message_id' => null,
            'status' => ChannelMessageStatus::Received,
        ];
    }

    public function outbound(): static
    {
        return $this->state(fn (): array => [
            'direction' => ChannelDirection::Outbound,
            'wamid' => null,
            'dereu_message_id' => (string) Str::uuid(),
            'status' => ChannelMessageStatus::Queued,
        ]);
    }
}
