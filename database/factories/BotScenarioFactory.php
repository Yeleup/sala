<?php

namespace Database\Factories;

use App\Models\BotScenario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotScenario>
 */
class BotScenarioFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Главный сценарий',
            'draft_definition' => static::roleMenuDefinition(),
            'published_version' => 0,
        ];
    }

    /**
     * Publish the given graph (or the current draft) as version 1.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}|null  $definition
     */
    public function published(?array $definition = null): static
    {
        return $this->state(function (array $attributes) use ($definition): array {
            $published = $definition ?? $attributes['draft_definition'] ?? static::roleMenuDefinition();

            return [
                'draft_definition' => $published,
                'published_definition' => $published,
                'published_version' => 1,
                'published_at' => now(),
            ];
        });
    }

    /**
     * The layer-1 menu from the specification: greeting, then role choice.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function roleMenuDefinition(): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'greeting', 'type' => 'text', 'text' => 'Здравствуйте! Это сервис аренды спецтехники и услуг.'],
                ['id' => 'role_menu', 'type' => 'buttons', 'text' => 'Кто вы?', 'options' => [
                    ['id' => 'supplier_equipment', 'title' => 'Поставщик техники'],
                    ['id' => 'supplier_services', 'title' => 'Поставщик услуг'],
                    ['id' => 'customer', 'title' => 'Заказчик'],
                ]],
            ],
            'edges' => [
                ['from' => 'start', 'output' => 'continue', 'to' => 'greeting'],
                ['from' => 'greeting', 'output' => 'continue', 'to' => 'role_menu'],
            ],
        ];
    }
}
