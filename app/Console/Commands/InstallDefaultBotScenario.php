<?php

namespace App\Console\Commands;

use App\Enums\BotScenarioTrigger;
use App\Models\BotScenario;
use App\Services\Bot\ScenarioValidator;
use App\Services\WhatsappTemplateLibrary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Installs and publishes the reference MVP scenarios
 * (docs/modules/user-flows.md): the main dialog (supplier branches
 * through the AI collector, customer search, «Мои объявления» CTA) and
 * the two flow scenarios — the customer request notification and the
 * 30-day renewal poll. Refuses to overwrite an already published
 * scenario without --force so a customized graph is not lost; each
 * scenario is judged separately.
 */
#[Signature('bot:install-default-scenario {--force : Перезаписать уже опубликованные сценарии}')]
#[Description('Установить и опубликовать типовые сценарии бота (главный диалог, заявка, продление)')]
class InstallDefaultBotScenario extends Command
{
    public function handle(ScenarioValidator $validator): int
    {
        $failures = 0;

        foreach ($this->scenarios() as $spec) {
            $failures += $this->install($validator, $spec['trigger'], $spec['name'], $spec['definition']) ? 0 : 1;
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $definition
     */
    protected function install(ScenarioValidator $validator, BotScenarioTrigger $trigger, string $name, array $definition): bool
    {
        $scenario = BotScenario::query()->where('trigger', $trigger)->orderBy('id')->first()
            ?? new BotScenario(['name' => $name, 'trigger' => $trigger]);

        if ($scenario->isPublished() && ! $this->option('force')) {
            $this->error("«{$name}»: сценарий уже опубликован — запустите с --force, чтобы перезаписать его типовым.");

            return false;
        }

        ['errors' => $errors] = $validator->validate($definition, $trigger);

        if ($errors !== []) {
            $this->error("«{$name}»: типовой сценарий не прошёл валидацию: ".implode(' ', $errors));

            return false;
        }

        $scenario->draft_definition = $definition;
        $scenario->save();
        $scenario->publishDraft();

        $this->info(sprintf(
            '«%s» опубликован (версия %d): %d блоков, %d связей.',
            $name,
            $scenario->published_version,
            count($definition['nodes']),
            count($definition['edges']),
        ));

        return true;
    }

    /**
     * @return list<array{trigger: BotScenarioTrigger, name: string, definition: array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}}>
     */
    protected function scenarios(): array
    {
        return [
            ['trigger' => BotScenarioTrigger::InboundMessage, 'name' => 'Основной сценарий', 'definition' => $this->mainDialogDefinition()],
            ['trigger' => BotScenarioTrigger::NewCustomerRequest, 'name' => 'Новая заявка', 'definition' => $this->customerRequestDefinition()],
            ['trigger' => BotScenarioTrigger::ListingExpiring, 'name' => 'Продление объявления', 'definition' => $this->listingRenewalDefinition()],
        ];
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    protected function mainDialogDefinition(): array
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
                // Повторное обращение: без приветствия — сразу меню действий.
                ['from' => 'start', 'output' => 'returning', 'to' => 'main_menu'],
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

    /**
     * Новая заявка: адаптивное уведомление поставщику с кнопками
     * [Согласиться]/[Отказаться]; исход решает само действие — по уже
     * решённой заявке (в т.ч. при гонке двух ответов) запуск идёт по
     * выходу «Заявка уже решена», заказчик уведомляется об исходе.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    protected function customerRequestDefinition(): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'x' => 40, 'y' => 240],
                ['id' => 'poll', 'type' => 'message', 'x' => 260, 'y' => 240,
                    'channel' => 'adaptive',
                    'template_name' => WhatsappTemplateLibrary::NEW_CUSTOMER_REQUEST,
                    'variables' => ['listing.title', 'request.query'],
                    'options' => [
                        ['id' => 'accept', 'title' => 'Согласиться'],
                        ['id' => 'decline', 'title' => 'Отказаться'],
                    ]],
                ['id' => 'do_accept', 'type' => 'action', 'action' => 'accept_request', 'x' => 540, 'y' => 80],
                ['id' => 'do_decline', 'type' => 'action', 'action' => 'decline_request', 'x' => 540, 'y' => 400],
                ['id' => 'accepted_text', 'type' => 'text', 'x' => 820, 'y' => 80,
                    'text' => 'Отлично! Мы сообщим заказчику, что вы готовы взять заказ.'],
                ['id' => 'declined_text', 'type' => 'text', 'x' => 820, 'y' => 400,
                    'text' => 'Понятно, заявку отклонили. Объявление продолжает показываться в поиске.'],
                ['id' => 'already_decided', 'type' => 'text', 'x' => 820, 'y' => 240,
                    'text' => 'Ответ по этой заявке уже зафиксирован — решение не меняется.'],
                ['id' => 'notify_accept', 'type' => 'action', 'action' => 'notify_customer', 'x' => 1100, 'y' => 80],
                ['id' => 'notify_decline', 'type' => 'action', 'action' => 'notify_customer', 'x' => 1100, 'y' => 400],
                ['id' => 'end', 'type' => 'end', 'x' => 1380, 'y' => 240],
            ],
            'edges' => [
                ['from' => 'start', 'output' => 'continue', 'to' => 'poll'],
                ['from' => 'poll', 'output' => 'option:accept', 'to' => 'do_accept'],
                ['from' => 'poll', 'output' => 'option:decline', 'to' => 'do_decline'],
                ['from' => 'do_accept', 'output' => 'continue', 'to' => 'accepted_text'],
                ['from' => 'do_accept', 'output' => 'skipped', 'to' => 'already_decided'],
                ['from' => 'do_decline', 'output' => 'continue', 'to' => 'declined_text'],
                ['from' => 'do_decline', 'output' => 'skipped', 'to' => 'already_decided'],
                ['from' => 'accepted_text', 'output' => 'continue', 'to' => 'notify_accept'],
                ['from' => 'notify_accept', 'output' => 'continue', 'to' => 'end'],
                ['from' => 'declined_text', 'output' => 'continue', 'to' => 'notify_decline'],
                ['from' => 'notify_decline', 'output' => 'continue', 'to' => 'end'],
                ['from' => 'already_decided', 'output' => 'continue', 'to' => 'end'],
            ],
        ];
    }

    /**
     * Продление объявления: 30-дневный опрос актуальности. Запуск живёт
     * без таймаута: поздний ответ по уже заархивированному объявлению
     * (в т.ч. авто-архивом по истечении срока) идёт по выходу
     * «Объявление уже в архиве» самого действия — ничего не воскресает.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    protected function listingRenewalDefinition(): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'x' => 40, 'y' => 240],
                ['id' => 'poll', 'type' => 'message', 'x' => 260, 'y' => 240,
                    'channel' => 'adaptive',
                    'template_name' => WhatsappTemplateLibrary::LISTING_RENEWAL,
                    'variables' => ['listing.title'],
                    'options' => [
                        ['id' => 'yes', 'title' => 'Да, актуально'],
                        ['id' => 'no', 'title' => 'Нет, в архив'],
                    ]],
                ['id' => 'do_renew', 'type' => 'action', 'action' => 'renew_listing', 'x' => 540, 'y' => 80],
                ['id' => 'do_archive', 'type' => 'action', 'action' => 'archive_listing', 'x' => 540, 'y' => 400],
                ['id' => 'renewed_text', 'type' => 'text', 'x' => 820, 'y' => 80,
                    'text' => 'Продлили: объявление «{{listing.title}}» будет показываться ещё 30 дней.'],
                ['id' => 'archived_text', 'type' => 'text', 'x' => 820, 'y' => 400,
                    'text' => 'Перенесли объявление в архив — оно больше не показывается в поиске.'],
                ['id' => 'already_archived', 'type' => 'text', 'x' => 820, 'y' => 240,
                    'text' => 'Это объявление уже в архиве. Чтобы разместить его снова, создайте новое объявление.'],
                ['id' => 'end', 'type' => 'end', 'x' => 1100, 'y' => 240],
            ],
            'edges' => [
                ['from' => 'start', 'output' => 'continue', 'to' => 'poll'],
                ['from' => 'poll', 'output' => 'option:yes', 'to' => 'do_renew'],
                ['from' => 'poll', 'output' => 'option:no', 'to' => 'do_archive'],
                ['from' => 'do_renew', 'output' => 'continue', 'to' => 'renewed_text'],
                ['from' => 'do_renew', 'output' => 'skipped', 'to' => 'already_archived'],
                ['from' => 'do_archive', 'output' => 'continue', 'to' => 'archived_text'],
                ['from' => 'do_archive', 'output' => 'skipped', 'to' => 'already_archived'],
                ['from' => 'renewed_text', 'output' => 'continue', 'to' => 'end'],
                ['from' => 'archived_text', 'output' => 'continue', 'to' => 'end'],
                ['from' => 'already_archived', 'output' => 'continue', 'to' => 'end'],
            ],
        ];
    }

}
