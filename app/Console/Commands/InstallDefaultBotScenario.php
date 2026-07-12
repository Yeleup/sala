<?php

namespace App\Console\Commands;

use App\Models\BotScenario;
use App\Services\Bot\ScenarioValidator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Installs and publishes the reference MVP scenario
 * (docs/modules/user-flows.md): the main menu with the supplier branches
 * (equipment / service through the AI collector), the customer search and
 * the «Мои объявления» CTA handoff. Refuses to overwrite an existing
 * published scenario without --force so a customized graph is not lost.
 */
#[Signature('bot:install-default-scenario {--force : Перезаписать уже опубликованный сценарий}')]
#[Description('Установить и опубликовать типовой сценарий бота (ветки поставщика, поиск заказчика, мои объявления)')]
class InstallDefaultBotScenario extends Command
{
    public function handle(ScenarioValidator $validator): int
    {
        $scenario = BotScenario::main() ?? new BotScenario(['name' => 'Основной сценарий']);

        if ($scenario->isPublished() && ! $this->option('force')) {
            $this->error('Сценарий уже опубликован — запустите с --force, чтобы перезаписать его типовым.');

            return self::FAILURE;
        }

        $definition = $this->definition();
        ['errors' => $errors] = $validator->validate($definition);

        if ($errors !== []) {
            $this->error('Типовой сценарий не прошёл валидацию: '.implode(' ', $errors));

            return self::FAILURE;
        }

        $scenario->draft_definition = $definition;
        $scenario->save();
        $scenario->publishDraft();

        $this->info(sprintf(
            'Типовой сценарий опубликован (версия %d): %d блоков, %d связей.',
            $scenario->published_version,
            count($definition['nodes']),
            count($definition['edges']),
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    protected function definition(): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'x' => 40, 'y' => 240],
                ['id' => 'greeting', 'type' => 'text', 'x' => 260, 'y' => 240,
                    'text' => 'Здравствуйте! Это сервис аренды спецтехники и услуг: разместите своё предложение или найдите технику и специалистов.'],
                ['id' => 'main_menu', 'type' => 'buttons', 'x' => 520, 'y' => 240,
                    'text' => 'Что вы хотите сделать?',
                    'options' => [
                        ['id' => 'supplier', 'title' => 'Я поставщик'],
                        ['id' => 'customer', 'title' => 'Я заказчик'],
                        ['id' => 'my', 'title' => 'Мои объявления'],
                    ]],
                ['id' => 'supplier_type', 'type' => 'buttons', 'x' => 800, 'y' => 80,
                    'text' => 'Что вы предлагаете?',
                    'options' => [
                        ['id' => 'equipment', 'title' => 'Техника'],
                        ['id' => 'service', 'title' => 'Услуга'],
                    ]],
                ['id' => 'collect_equipment', 'type' => 'ai', 'task' => 'collect_listing', 'listing_type' => 'equipment', 'x' => 1080, 'y' => 20],
                ['id' => 'collect_service', 'type' => 'ai', 'task' => 'collect_listing', 'listing_type' => 'service', 'x' => 1080, 'y' => 150],
                ['id' => 'after_collect', 'type' => 'text', 'x' => 1340, 'y' => 80,
                    'text' => 'Чтобы добавить ещё одно объявление или найти технику — просто напишите нам снова.'],
                ['id' => 'customer_search', 'type' => 'ai', 'task' => 'customer_search', 'x' => 800, 'y' => 300],
                ['id' => 'after_search', 'type' => 'text', 'x' => 1080, 'y' => 300,
                    'text' => 'Спасибо, что воспользовались сервисом! Напишите нам снова, когда что-то понадобится.'],
                ['id' => 'my_listings', 'type' => 'my_listings', 'x' => 800, 'y' => 430,
                    'text' => 'Откройте кабинет — там ваши объявления, статусы, причины отклонения и снятие с публикации.'],
            ],
            'edges' => [
                ['from' => 'start', 'output' => 'continue', 'to' => 'greeting'],
                ['from' => 'greeting', 'output' => 'continue', 'to' => 'main_menu'],
                ['from' => 'main_menu', 'output' => 'option:supplier', 'to' => 'supplier_type'],
                ['from' => 'main_menu', 'output' => 'option:customer', 'to' => 'customer_search'],
                ['from' => 'main_menu', 'output' => 'option:my', 'to' => 'my_listings'],
                ['from' => 'supplier_type', 'output' => 'option:equipment', 'to' => 'collect_equipment'],
                ['from' => 'supplier_type', 'output' => 'option:service', 'to' => 'collect_service'],
                ['from' => 'collect_equipment', 'output' => 'continue', 'to' => 'after_collect'],
                ['from' => 'collect_service', 'output' => 'continue', 'to' => 'after_collect'],
                ['from' => 'customer_search', 'output' => 'continue', 'to' => 'after_search'],
            ],
        ];
    }
}
