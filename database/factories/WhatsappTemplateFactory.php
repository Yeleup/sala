<?php

namespace Database\Factories;

use App\Enums\WhatsappTemplateCategory;
use App\Enums\WhatsappTemplateStatus;
use App\Models\WhatsappTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappTemplate>
 */
class WhatsappTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['listing_renewal', 'request_notification', 'listing_update']).'_'.fake()->unique()->numberBetween(1, 9999),
            'language' => 'ru',
            'category' => WhatsappTemplateCategory::Utility,
            'status' => WhatsappTemplateStatus::Pending,
            'rejection_reason' => null,
            'body' => 'Ваше объявление «{{1}}» скоро перестанет показываться. Оно ещё актуально?',
            'components' => null,
            'dereu_template_id' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => WhatsappTemplateStatus::Approved]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => WhatsappTemplateStatus::Rejected,
            'rejection_reason' => 'Template violates WhatsApp policy.',
        ]);
    }

    public function marketing(): static
    {
        return $this->state(fn (): array => ['category' => WhatsappTemplateCategory::Marketing]);
    }
}
