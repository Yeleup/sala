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
 * (docs/modules/user-flows.md): the main dialog (the supplier branch
 * through the AI collector, customer search, «Мои объявления» CTA) and
 * the three flow scenarios — the customer request notification and the
 * 30-day renewal poll in its per-listing and per-supplier shapes.
 * Refuses to overwrite an already published
 * scenario without --force so a customized graph is not lost; each
 * scenario is judged separately.
 */
#[Signature('bot:install-default-scenario {--force : Перезаписать уже опубликованные сценарии} {--only= : Ограничить одним триггером — чтобы --force не задел остальные}')]
#[Description('Установить и опубликовать типовые сценарии бота (главный диалог, заявка, продление)')]
class InstallDefaultBotScenario extends Command
{
    public function handle(ScenarioValidator $validator): int
    {
        $scenarios = $this->selectedScenarios();

        if ($scenarios === null) {
            return self::FAILURE;
        }

        $failures = 0;

        foreach ($scenarios as $spec) {
            $failures += $this->install($validator, $spec['trigger'], $spec['name'], $spec['definition']) ? 0 : 1;
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * --force перезаписывает опубликованные сценарии, и без ограничения
     * оно накрывает все сразу — включая главный диалог, который оператор
     * почти наверняка правил под себя. --only=<триггер> сужает и выбор
     * сценариев, и радиус --force до одного из них.
     *
     * Null — триггер указан неизвестный; сообщение уже выведено.
     *
     * @return list<array{trigger: BotScenarioTrigger, name: string, definition: array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}}>|null
     */
    protected function selectedScenarios(): ?array
    {
        $only = $this->option('only');

        if (blank($only)) {
            return $this->scenarios();
        }

        $trigger = BotScenarioTrigger::tryFrom((string) $only);

        if ($trigger === null) {
            $this->error(sprintf(
                'Неизвестный триггер «%s». Доступны: %s.',
                $only,
                implode(', ', array_column(BotScenarioTrigger::cases(), 'value')),
            ));

            return null;
        }

        $selected = array_values(array_filter(
            $this->scenarios(),
            fn (array $spec): bool => $spec['trigger'] === $trigger,
        ));

        if ($selected === []) {
            $this->error("Для триггера «{$trigger->label()}» типового сценария нет.");

            return null;
        }

        return $selected;
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
            ['trigger' => BotScenarioTrigger::ListingsExpiringBatch, 'name' => 'Продление нескольких объявлений', 'definition' => $this->listingsRenewalBatchDefinition()],
        ];
    }

    /**
     * Главный диалог: меню в два шага на кнопках. Первый шаг — три вида
     * объявлений (аренда, ремонт, водитель); второй — роль внутри вида
     * («сдаю» / «ищу») плюс «Мои объявления» третьей кнопкой. Четыре
     * пункта верхнего уровня в одно сообщение не помещаются: лимит
     * WhatsApp — три reply-кнопки. Прежние id вариантов сохранены, чтобы
     * кнопки прежней версии сценария, висящие в чатах, продолжали
     * работать. Текст AI-блокам не задаётся: приветствие подставляется
     * из выбранного вида. Завершение любой ветки (сбор объявления, поиск,
     * «Мои объявления») выходом «Продолжить» возвращает контакта в
     * главное меню — тупиковых узлов в графе нет.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    protected function mainDialogDefinition(): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'x' => 40, 'y' => 400],
                ['id' => 'greeting', 'type' => 'text', 'x' => 260, 'y' => 400,
                    'text' => 'Здравствуйте! Это сервис спецтехники: здесь сдают и ищут технику, зовут мастеров по ремонту, находят водителей и машинистов.'],
                ['id' => 'main_menu', 'type' => 'buttons', 'x' => 500, 'y' => 400,
                    'text' => 'Что вас интересует?',
                    'options' => [
                        ['id' => 'kind_rental', 'title' => 'Аренда спецтехники'],
                        ['id' => 'kind_repair', 'title' => 'Ремонт спецтехники'],
                        ['id' => 'kind_driver', 'title' => 'Водитель / машинист'],
                    ]],
                ['id' => 'menu_rental', 'type' => 'buttons', 'x' => 740, 'y' => 140,
                    'text' => 'Аренда спецтехники. Вы предлагаете технику или ищете?',
                    'options' => [
                        ['id' => 'rent_out', 'title' => 'Я сдаю спецтехнику'],
                        ['id' => 'rent_seek', 'title' => 'Я ищу спецтехнику'],
                        ['id' => 'my', 'title' => 'Мои объявления'],
                    ]],
                ['id' => 'menu_repair', 'type' => 'buttons', 'x' => 740, 'y' => 400,
                    'text' => 'Ремонт спецтехники. Вы мастер или ищете мастера?',
                    'options' => [
                        ['id' => 'master', 'title' => 'Я мастер'],
                        ['id' => 'master_seek', 'title' => 'Я ищу мастера'],
                        ['id' => 'my_repair', 'title' => 'Мои объявления'],
                    ]],
                ['id' => 'menu_driver', 'type' => 'buttons', 'x' => 740, 'y' => 660,
                    'text' => 'Водители и машинисты. Вы водитель или ищете водителя?',
                    'options' => [
                        ['id' => 'driver', 'title' => 'Я водитель'],
                        ['id' => 'driver_seek', 'title' => 'Я ищу водителя'],
                        ['id' => 'my_driver', 'title' => 'Мои объявления'],
                    ]],
                ['id' => 'collect_rental', 'type' => 'ai', 'task' => 'collect_listing', 'kind' => 'rental', 'x' => 1000, 'y' => 40],
                ['id' => 'search_rental', 'type' => 'ai', 'task' => 'customer_search', 'kind' => 'rental', 'x' => 1000, 'y' => 160],
                ['id' => 'collect_repair', 'type' => 'ai', 'task' => 'collect_listing', 'kind' => 'repair', 'x' => 1000, 'y' => 300],
                ['id' => 'search_repair', 'type' => 'ai', 'task' => 'customer_search', 'kind' => 'repair', 'x' => 1000, 'y' => 420],
                ['id' => 'collect_driver', 'type' => 'ai', 'task' => 'collect_listing', 'kind' => 'driver', 'x' => 1000, 'y' => 560],
                ['id' => 'search_driver', 'type' => 'ai', 'task' => 'customer_search', 'kind' => 'driver', 'x' => 1000, 'y' => 680],
                ['id' => 'my_listings', 'type' => 'my_listings', 'x' => 1000, 'y' => 820,
                    'text' => 'Ваши объявления собраны в кабинете: статусы, причины отклонения, снятие с публикации. Кнопка ниже откроет его без пароля.'],
            ],
            'edges' => [
                ['from' => 'start', 'output' => 'continue', 'to' => 'greeting'],
                // Повторное обращение: без приветствия — сразу меню действий.
                ['from' => 'start', 'output' => 'returning', 'to' => 'main_menu'],
                ['from' => 'greeting', 'output' => 'continue', 'to' => 'main_menu'],
                ['from' => 'main_menu', 'output' => 'option:kind_rental', 'to' => 'menu_rental'],
                ['from' => 'main_menu', 'output' => 'option:kind_repair', 'to' => 'menu_repair'],
                ['from' => 'main_menu', 'output' => 'option:kind_driver', 'to' => 'menu_driver'],
                ['from' => 'menu_rental', 'output' => 'option:rent_out', 'to' => 'collect_rental'],
                ['from' => 'menu_rental', 'output' => 'option:rent_seek', 'to' => 'search_rental'],
                ['from' => 'menu_rental', 'output' => 'option:my', 'to' => 'my_listings'],
                ['from' => 'menu_repair', 'output' => 'option:master', 'to' => 'collect_repair'],
                ['from' => 'menu_repair', 'output' => 'option:master_seek', 'to' => 'search_repair'],
                ['from' => 'menu_repair', 'output' => 'option:my_repair', 'to' => 'my_listings'],
                ['from' => 'menu_driver', 'output' => 'option:driver', 'to' => 'collect_driver'],
                ['from' => 'menu_driver', 'output' => 'option:driver_seek', 'to' => 'search_driver'],
                ['from' => 'menu_driver', 'output' => 'option:my_driver', 'to' => 'my_listings'],
                // Завершение любой ветки возвращает в главное меню — тупиковых узлов нет.
                ['from' => 'collect_rental', 'output' => 'continue', 'to' => 'main_menu'],
                ['from' => 'collect_repair', 'output' => 'continue', 'to' => 'main_menu'],
                ['from' => 'collect_driver', 'output' => 'continue', 'to' => 'main_menu'],
                ['from' => 'search_rental', 'output' => 'continue', 'to' => 'main_menu'],
                ['from' => 'search_repair', 'output' => 'continue', 'to' => 'main_menu'],
                ['from' => 'search_driver', 'output' => 'continue', 'to' => 'main_menu'],
                ['from' => 'my_listings', 'output' => 'continue', 'to' => 'main_menu'],
            ],
        ];
    }

    /**
     * Новая заявка: адаптивное уведомление поставщику с кнопками
     * [Согласиться]/[Отказаться]; исход решает само действие — по уже
     * решённой заявке (в т.ч. при гонке двух ответов) запуск идёт по
     * выходу «Заявка уже решена», заказчик уведомляется об исходе.
     * Молчание поставщика не длится вечно: под конец суток ожидания
     * ветка таймаута закрывает заявку статусом «Без ответа» (повторная
     * заявка того же заказчика по этому объявлению снова возможна), и
     * заказчик честно узнаёт, что ответа не было. Таймаут 22 часа, а не
     * 24, не случайно: уведомление заказчику — обычное сессионное
     * сообщение, и с часовым шагом свипа оно обязано успеть в 24-часовое
     * окно, которое заказчик открыл выбором варианта.
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
                    'timeout_hours' => 22,
                    'options' => [
                        ['id' => 'accept', 'title' => 'Согласиться'],
                        ['id' => 'decline', 'title' => 'Отказаться'],
                    ]],
                ['id' => 'do_accept', 'type' => 'action', 'action' => 'accept_request', 'x' => 540, 'y' => 80],
                ['id' => 'do_decline', 'type' => 'action', 'action' => 'decline_request', 'x' => 540, 'y' => 400],
                ['id' => 'do_expire', 'type' => 'action', 'action' => 'expire_request', 'x' => 540, 'y' => 560],
                ['id' => 'accepted_text', 'type' => 'text', 'x' => 820, 'y' => 80,
                    'text' => 'Отлично! Мы сообщим заказчику, что вы готовы взять заказ.'],
                ['id' => 'declined_text', 'type' => 'text', 'x' => 820, 'y' => 400,
                    'text' => 'Понятно, заявку отклонили. Объявление продолжает показываться в поиске.'],
                ['id' => 'already_decided', 'type' => 'text', 'x' => 820, 'y' => 240,
                    'text' => 'По этой заявке вы уже ответили — первое решение осталось в силе.'],
                ['id' => 'notify_accept', 'type' => 'action', 'action' => 'notify_customer', 'x' => 1100, 'y' => 80],
                ['id' => 'notify_decline', 'type' => 'action', 'action' => 'notify_customer', 'x' => 1100, 'y' => 400],
                ['id' => 'notify_timeout', 'type' => 'action', 'action' => 'notify_customer', 'x' => 1100, 'y' => 560],
                ['id' => 'end', 'type' => 'end', 'x' => 1380, 'y' => 240],
            ],
            'edges' => [
                ['from' => 'start', 'output' => 'continue', 'to' => 'poll'],
                ['from' => 'poll', 'output' => 'option:accept', 'to' => 'do_accept'],
                ['from' => 'poll', 'output' => 'option:decline', 'to' => 'do_decline'],
                ['from' => 'poll', 'output' => 'timeout', 'to' => 'do_expire'],
                ['from' => 'do_expire', 'output' => 'continue', 'to' => 'notify_timeout'],
                ['from' => 'notify_timeout', 'output' => 'continue', 'to' => 'end'],
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
                    'text' => 'Это объявление уже в архиве. Вернуть его в поиск можно в кабинете — кнопка «Вернуть в поиск» в «Моих объявлениях».'],
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

    /**
     * Продление пачкой: у поставщика за сутки истекает сразу несколько
     * публикаций, и вместо платного шаблона на каждую уходит один вопрос
     * обо всех. [Все актуальны] и [Все в архив] решают за всю пачку;
     * [Разобрать по одному] уводит в кабинет, где видно каждое
     * объявление по отдельности — нажатие кнопки открывает 24-часовое
     * окно, поэтому CTA-ссылка уходит бесплатным сообщением. Таймаута
     * нет: молчание и так уводит публикации в архив по истечении срока, а
     * поздний ответ идёт по выходу «Все объявления уже в архиве».
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    protected function listingsRenewalBatchDefinition(): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'x' => 40, 'y' => 300],
                ['id' => 'poll', 'type' => 'message', 'x' => 260, 'y' => 300,
                    'channel' => 'adaptive',
                    'template_name' => WhatsappTemplateLibrary::SEVERAL_LISTINGS_RENEWAL,
                    'variables' => ['listings.expiring_first', 'listings.expiring_rest'],
                    'options' => [
                        ['id' => 'all_yes', 'title' => 'Все актуальны'],
                        ['id' => 'one_by_one', 'title' => 'Разобрать по одному'],
                        ['id' => 'all_no', 'title' => 'Все в архив'],
                    ]],
                ['id' => 'do_renew_all', 'type' => 'action', 'action' => 'renew_batch_listings', 'x' => 560, 'y' => 80],
                ['id' => 'do_archive_all', 'type' => 'action', 'action' => 'archive_batch_listings', 'x' => 560, 'y' => 520],
                ['id' => 'renewed_text', 'type' => 'text', 'x' => 860, 'y' => 80,
                    'text' => 'Продлили: эти объявления будут показываться ещё 30 дней.'],
                ['id' => 'archived_text', 'type' => 'text', 'x' => 860, 'y' => 520,
                    'text' => 'Перенесли эти объявления в архив — они больше не показываются в поиске.'],
                ['id' => 'already_archived', 'type' => 'text', 'x' => 860, 'y' => 300,
                    'text' => 'По этим объявлениям вопрос уже закрыт — актуальные статусы и сроки показа видны в кабинете.'],
                ['id' => 'cabinet', 'type' => 'my_listings', 'x' => 560, 'y' => 680,
                    'text' => 'Хорошо. Откройте кабинет — там видно, у каких объявлений заканчивается срок, и можно продлить нужные.'],
                ['id' => 'end', 'type' => 'end', 'x' => 1160, 'y' => 300],
            ],
            'edges' => [
                ['from' => 'start', 'output' => 'continue', 'to' => 'poll'],
                ['from' => 'poll', 'output' => 'option:all_yes', 'to' => 'do_renew_all'],
                ['from' => 'poll', 'output' => 'option:all_no', 'to' => 'do_archive_all'],
                ['from' => 'poll', 'output' => 'option:one_by_one', 'to' => 'cabinet'],
                ['from' => 'do_renew_all', 'output' => 'continue', 'to' => 'renewed_text'],
                ['from' => 'do_renew_all', 'output' => 'skipped', 'to' => 'already_archived'],
                ['from' => 'do_archive_all', 'output' => 'continue', 'to' => 'archived_text'],
                ['from' => 'do_archive_all', 'output' => 'skipped', 'to' => 'already_archived'],
                ['from' => 'renewed_text', 'output' => 'continue', 'to' => 'end'],
                ['from' => 'archived_text', 'output' => 'continue', 'to' => 'end'],
                ['from' => 'already_archived', 'output' => 'continue', 'to' => 'end'],
                ['from' => 'cabinet', 'output' => 'continue', 'to' => 'end'],
            ],
        ];
    }
}
