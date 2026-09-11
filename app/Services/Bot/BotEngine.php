<?php

namespace App\Services\Bot;

use App\Enums\AiOutcome;
use App\Enums\BotNodeType;
use App\Enums\BotReplyKey;
use App\Enums\MenuRouteKind;
use App\Enums\RouteConfidence;
use App\Exceptions\OutboundRequestBlocked;
use App\Models\BotScenario;
use App\Models\BotSession;
use App\Models\Contact;
use App\Services\Ai\CtaLinkBuilder;
use App\Services\Ai\SupplierListingCollector;
use App\Services\Ai\VoiceTranscriber;
use App\Services\DereuMediaDownloader;
use App\Services\DereuMessenger;
use App\Services\OperatorHandoff;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Drives a contact through the published scenario graph.
 *
 * Called once per inbound message. Auto-advances through non-waiting
 * blocks (sending their messages) until the graph stops at a block that
 * waits for input, or the branch ends. Between dialogs the session holds
 * no current node, and the next inbound message starts from «Старт».
 */
class BotEngine
{
    /**
     * Safety cap on auto-advanced blocks per inbound message, so a
     * mis-published cyclic graph cannot spam the contact forever.
     */
    private const int MAX_STEPS = 20;

    private const string DEFAULT_LIST_BUTTON = 'Выбрать';

    /**
     * The single button under a navigation offer. Not part of any published
     * graph — the engine answers for it itself, before button routing, or a
     * press would be read as a button from an older version.
     */
    private const string NAV_CONFIRM = 'nav_confirm';

    private const string NAV_CONFIRM_GO_TITLE = 'Перейти';

    private const string NAV_CONFIRM_RESUME_TITLE = 'Продолжить';

    /**
     * How long an offered route stays confirmable. Past that the offer is
     * older than what the contact was talking about, and pressing it would
     * carry a stale message into a branch.
     */
    private const int NAV_PROPOSAL_TTL_MINUTES = 30;

    /**
     * How many times in a row the bot shows the same menu to a message it
     * did not understand before it stops showing it.
     *
     * Two is what a person can read as «I mis-typed, let me try again»; a
     * third is the bot arguing. After that the step is not repeated at all:
     * the menu is still on screen further up, its buttons keep working from
     * any step, and one contact was shown «Что вас интересует?» 29 times.
     */
    private const int MENU_REPEAT_LIMIT = 2;

    /** The "resume the interrupted questionnaire" destination of an offer. */
    private const string NAV_ROUTE_RESUME = 'resume';

    /**
     * A voice message at a menu step is not free — it costs a
     * transcription plus the classifier call — and a 24-hour window is
     * open to any number that wrote once. These cap that per contact.
     */
    private const string VOICE_RATE_LIMIT_KEY = 'menu-voice:';

    private const int VOICE_RATE_LIMIT_ATTEMPTS = 5;

    private const int VOICE_RATE_LIMIT_SECONDS = 3600;

    public function __construct(
        private readonly DereuMessenger $messenger,
        private readonly AiAssistant $aiAssistant,
        private readonly ScenarioRunReplyHandler $runReplies,
        private readonly NotificationReplyHandler $notificationReplies,
        private readonly CtaLinkBuilder $links,
        private readonly BotReplyTexts $replyTexts,
        private readonly MenuRouter $menuRouter,
        private readonly DereuMediaDownloader $mediaDownloader,
        private readonly VoiceTranscriber $transcriber,
        private readonly OperatorHandoff $handoff,
    ) {}

    public function handle(Contact $contact, InboundMessage $message): void
    {
        // Ответ на кнопку, которую бот поставил в переписку сам, отвечается
        // раньше всего остального — и в паузе оператора тоже: он закрывает
        // вопрос бота, а не начинает разговор. Отложить его некуда, событие
        // помечается обработанным и не проигрывается второй раз, так что
        // проглоченное нажатие — это открытый запуск и объявление,
        // ушедшее в архив по молчанию.
        if ($this->handleProactiveReply($contact, $message)) {
            return;
        }

        // Дальше бот молчит, пока разговор ведёт живой человек: двое,
        // отвечающих одному, — это ровно то, ради чего пауза и заведена.
        if ($this->handoff->isActive($contact)) {
            return;
        }

        // Пауза кончилась сама. Диалог закрывается так же, как по кнопке
        // «Вернуть бота»: иначе бот очнулся бы на «Что вас интересует?» —
        // вопросе, на который человек час назад ответил человеку.
        $this->handoff->releaseExpired($contact);

        $scenario = BotScenario::main();
        $definition = $scenario?->publishedDefinition();

        if ($scenario === null || $definition === null) {
            return;
        }

        // A press Meta could not deliver content for cannot be resolved (no
        // token, no title) — explain once and stop. The session is deliberately
        // untouched: an active dialog stays parked on its step, none is started.
        if ($message->unrecognizedPress) {
            $this->messenger->sendText($contact, $this->replyTexts->get(BotReplyKey::UnrecognizedPress));

            return;
        }

        $session = BotSession::query()->firstOrNew(['contact_id' => $contact->id]);

        if ($this->startsNewDialog($session, $scenario, $definition)) {
            $this->openDialog($session, $contact, $scenario, $definition, $message);

            return;
        }

        // Soft update: the awaited block survived republication, so the
        // contact continues on the new version without losing the step.
        $session->scenario_version = $scenario->published_version;
        $session->updated_at = now();

        $node = $definition->node($session->current_node_id);
        $type = $definition->nodeType($node);

        // An answer to the navigator's own offer. Must run before button
        // routing: «nav_confirm» exists in no published graph, so routeButton
        // would swallow it as a button from an older version.
        if ($this->handleNavProposal($session, $contact, $scenario, $definition, $node, $type, $message)) {
            return;
        }

        // A pressed scenario button routes by its machine id — even when it
        // came from an earlier bot message and no longer matches the block
        // the contact is standing on. This must run before the AI block, so
        // a stray button press is never swallowed as a search query.
        if (filled($message->replyId) && $this->routeButton($session, $contact, $scenario, $definition, $node, $type, $message)) {
            return;
        }

        if ($type === BotNodeType::AiInput) {
            $this->resumeAi($session, $contact, $definition, $node, $message);

            return;
        }

        if ($type?->waitsForInput() !== true) {
            $this->restart($session, $contact, $scenario, $definition);

            return;
        }

        $this->handleMenuReply($session, $contact, $definition, $node, $message);
    }

    /**
     * A reply to a button the bot put into the conversation itself — a
     * scenario run (the renewal poll) or a built-in notification (the
     * moderation verdict). Neither belongs to the main dialog: they can
     * land at any step, and they close a question the bot asked rather
     * than open one of the contact's own.
     */
    private function handleProactiveReply(Contact $contact, InboundMessage $message): bool
    {
        // Buttons of scenario runs carry flow:{token}:{option} payloads
        // and route to their own run — they never enter the main dialog.
        if ($this->runReplies->handle($contact, $message)) {
            return true;
        }

        // Replies to built-in proactive notifications (the moderation
        // verdict button, plus legacy buttons sent before the flows moved
        // into scenarios) can also arrive at any step.
        return $this->notificationReplies->handle($contact, $message);
    }

    /**
     * Handle a pressed button by its machine id. Returns true when it fully
     * handled the message; false to let the normal per-block flow run.
     *
     * @param  array<string, mixed>|null  $node
     */
    private function routeButton(BotSession $session, Contact $contact, BotScenario $scenario, ScenarioDefinition $definition, ?array $node, ?BotNodeType $type, InboundMessage $message): bool
    {
        $owner = $definition->optionOwner((string) $message->replyId);

        if ($owner !== null) {
            // The current block's own option — matchOption handles it below.
            if ($node !== null && $owner['node_id'] === ($node['id'] ?? null)) {
                return false;
            }

            // A button from another section (an earlier menu still visible in
            // the chat): honour it, discarding any unfinished AI progress —
            // the contact explicitly asked for a different branch.
            $this->routeToOption($session, $contact, $definition, $owner);

            return true;
        }

        // Not a published-graph button. An AI block owns runtime buttons of
        // its own (result rows, «Искать шире», «В меню») — leave them to the
        // assistant.
        if ($type === BotNodeType::AiInput) {
            return false;
        }

        // A button from an older published version: nothing in the current
        // graph answers to it.
        $this->handleStaleButton($session, $contact, $scenario, $definition, $node, $type);

        return true;
    }

    /**
     * @param  array{node_id: string, option_id: string}  $owner
     * @param  InboundMessage|null  $carried  Set when the navigator read the section
     *                                        out of what the contact wrote — the text
     *                                        travels into the branch so nobody types
     *                                        it twice. A pressed button carries
     *                                        nothing: it says where to go, not what.
     */
    private function routeToOption(BotSession $session, Contact $contact, ScenarioDefinition $definition, array $owner, ?InboundMessage $carried = null): void
    {
        // Jumping away from an AI block abandons its working memory.
        if ($session->state !== null) {
            $session->state = null;
        }

        $target = $definition->target($owner['node_id'], ScenarioDefinition::optionOutput($owner['option_id']));

        $this->advance($session, $contact, $definition, $target, carried: $carried);
    }

    /**
     * @param  array<string, mixed>|null  $node
     */
    private function handleStaleButton(BotSession $session, Contact $contact, BotScenario $scenario, ScenarioDefinition $definition, ?array $node, ?BotNodeType $type): void
    {
        $this->messenger->sendText($contact, $this->replyTexts->get(BotReplyKey::StaleButton));

        // Waiting on a menu — repeat the step the contact is actually on.
        if ($node !== null && $type?->waitsForInput() === true) {
            $this->sendMenu($contact, $definition, $node);
            $session->save();

            return;
        }

        // Nothing awaited — start a fresh dialog from «Старт».
        $this->restart($session, $contact, $scenario, $definition);
    }

    /**
     * A new dialog starts from «Старт» when there is no active session,
     * the previous dialog ended or went silent for 24 hours, or a
     * republication removed or reshaped the awaited block (критический
     * конфликт узлов — мягкий сброс).
     */
    private function startsNewDialog(BotSession $session, BotScenario $scenario, ScenarioDefinition $definition): bool
    {
        if (! $session->exists || $session->bot_scenario_id !== $scenario->id) {
            return true;
        }

        if ($session->current_node_id === null || $session->isExpired()) {
            return true;
        }

        $node = $definition->node($session->current_node_id);

        if ($session->scenario_version !== $scenario->published_version) {
            if ($node === null || $definition->nodeType($node)?->waitsForInput() !== true) {
                return true;
            }

            // The block survived but changed its type, options or AI task —
            // the contact answered a different question than the new schema
            // asks. Sessions from before fingerprints are trusted as-is.
            return $session->current_node_fingerprint !== null
                && $session->current_node_fingerprint !== $definition->nodeFingerprint($node);
        }

        return $node === null;
    }

    private function restart(BotSession $session, Contact $contact, BotScenario $scenario, ScenarioDefinition $definition): void
    {
        $this->clearMenuStreak($session);
        $session->bot_scenario_id = $scenario->id;
        $session->scenario_version = $scenario->published_version;
        $session->updated_at = now();

        $this->advance($session, $contact, $definition, $definition->startNodeId());
    }

    /**
     * Walk the graph from the given node: send block messages, follow
     * "continue" transitions, stop at the first block that waits for input.
     *
     * $silentMenuAt names a menu the walk should park at without showing —
     * the dialog is opening and the navigator has already read the first
     * message as a destination, so the menu would be a question the bot
     * answers itself three seconds later.
     *
     * $carried is the message that brought the contact here; the first AI
     * block on the way answers by it instead of introducing itself. It is
     * spent on that block: a second AI block further along the walk did
     * not receive it and still needs to say what it wants.
     *
     * @param  array<string, mixed>|null  $node
     */
    private function advance(BotSession $session, Contact $contact, ScenarioDefinition $definition, ?string $nodeId, ?string $silentMenuAt = null, ?InboundMessage $carried = null): void
    {
        for ($steps = 0; $steps < self::MAX_STEPS; $steps++) {
            $node = $definition->node($nodeId);
            $type = $definition->nodeType($node);

            if ($node === null || $type === null) {
                $this->endDialog($session);

                return;
            }

            switch ($type) {
                case BotNodeType::Start:
                    $nodeId = $definition->target($node['id'], $this->startOutput($session, $contact, $definition, $node['id']));
                    break;

                case BotNodeType::Text:
                    $text = (string) ($node['text'] ?? '');

                    if (filled($text)) {
                        $this->messenger->sendText($contact, $text);
                    }

                    $nodeId = $definition->target($node['id'], ScenarioDefinition::OUTPUT_CONTINUE);
                    break;

                case BotNodeType::MyListings:
                    $this->messenger->sendCtaUrl(
                        $contact,
                        (string) ($node['text'] ?? '') ?: 'Ваши объявления собраны в кабинете: статусы, причины отклонения, снятие с публикации. Кнопка ниже откроет его без пароля.',
                        'Открыть кабинет',
                        $this->links->myListingsUrl($contact),
                    );

                    $nodeId = $definition->target($node['id'], ScenarioDefinition::OUTPUT_CONTINUE);
                    break;

                case BotNodeType::ButtonMenu:
                case BotNodeType::ListMenu:
                    if ($node['id'] !== $silentMenuAt) {
                        $this->sendMenu($contact, $definition, $node);
                    }

                    $this->waitAt($session, $node['id'], $definition->nodeFingerprint($node));

                    return;

                case BotNodeType::AiInput:
                    $this->waitAt($session, $node['id'], $definition->nodeFingerprint($node));

                    $entering = $carried;
                    $carried = null;

                    if ($this->aiAssistant->start($session, $node, $entering) !== AiOutcome::Completed) {
                        return;
                    }

                    $nodeId = $definition->target($node['id'], ScenarioDefinition::OUTPUT_CONTINUE);
                    break;

                case BotNodeType::End:
                default:
                    // Blocks of run-based scenarios cannot be published
                    // into the main dialog — validation forbids them.
                    $this->endDialog($session);

                    return;
            }
        }

        // Step cap reached — a cycle of auto-advancing blocks; park the dialog.
        $this->endDialog($session);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function handleMenuReply(BotSession $session, Contact $contact, ScenarioDefinition $definition, array $node, InboundMessage $message): void
    {
        $optionId = $definition->matchOption($node, $message);

        if ($optionId !== null) {
            $this->clearMenuStreak($session);
            $this->advance($session, $contact, $definition, $definition->target($node['id'], ScenarioDefinition::optionOutput($optionId)));

            return;
        }

        $fallbackTarget = $definition->target($node['id'], ScenarioDefinition::OUTPUT_FALLBACK);

        if ($fallbackTarget !== null) {
            $this->clearMenuStreak($session);
            $this->advance($session, $contact, $definition, $fallbackTarget);

            return;
        }

        // Ничего из графа не подошло, «Любая другая фраза» не подключена —
        // последний шанс понять сказанное есть у ИИ-навигатора.
        if ($this->routeFreeText($session, $contact, $definition, $node, $message)) {
            return;
        }

        // Не понял и он — бот повторяет текущий шаг, пока повтор ещё
        // остаётся ответом.
        $this->repeatStep($session, $contact, $definition, $node, $message->text);
    }

    /**
     * Open a new dialog. The message that opens it says what the contact
     * wants just as much as the next one does, so it is classified
     * *before* the menu goes out — otherwise the bot asks «Что вас
     * интересует?» and answers its own question three seconds later, which
     * is exactly what it used to do.
     *
     * The greeting still goes out: someone writing for the first time
     * should learn where they landed. Only the menu is held back, and only
     * until it is clear whether it is needed at all.
     *
     * Where the graph answers by itself the navigator is not asked and the
     * walk is the ordinary one: a press is a destination in its own right,
     * voice is left to the ordinary menu turn, an entry block that is not
     * a menu has no options to route into, and a text matching an option
     * or a wired «Любая другая фраза» output is the graph's own business.
     */
    private function openDialog(BotSession $session, Contact $contact, BotScenario $scenario, ScenarioDefinition $definition, InboundMessage $message): void
    {
        $entry = $this->routableEntry($session, $contact, $definition, $message);

        if ($entry === null) {
            $this->restart($session, $contact, $scenario, $definition);

            return;
        }

        $this->clearMenuStreak($session);
        $session->bot_scenario_id = $scenario->id;
        $session->scenario_version = $scenario->published_version;
        $session->updated_at = now();

        // Everything before the menu — the greeting, any other text blocks
        // the operator put there — goes out now; the menu itself waits.
        $this->advance($session, $contact, $definition, $definition->startNodeId(), silentMenuAt: $entry['id']);

        // The walk may not have stopped where the pure walk said it would
        // (a block that sends can still complete the dialog), so route only
        // when the contact really is standing on that menu.
        if ($session->current_node_id !== $entry['id']) {
            return;
        }

        if ($this->routeFreeText($session, $contact, $definition, $entry, $message)) {
            return;
        }

        // The navigator understood nothing — the menu is owed after all.
        $this->repeatStep($session, $contact, $definition, $entry, $message->text);
    }

    /**
     * The menu a fresh dialog would park at, when the opening message is
     * worth asking the navigator about; null when it is not and the dialog
     * should simply be walked from «Старт».
     *
     * resolveTarget() walks the graph without sending anything or touching
     * the session — it answers «where would this stop» before the first
     * message goes out, which is the whole point of asking early.
     *
     * @return array<string, mixed>|null
     */
    private function routableEntry(BotSession $session, Contact $contact, ScenarioDefinition $definition, InboundMessage $message): ?array
    {
        if (filled($message->replyId) || trim((string) $message->text) === '') {
            return null;
        }

        $startId = $definition->startNodeId();

        if ($startId === null) {
            return null;
        }

        $entryId = $definition->resolveTarget($startId, $this->startOutput($session, $contact, $definition, $startId));
        $node = $entryId === null ? null : $definition->node($entryId);

        if ($node === null || ! $this->isMenu($definition, $node)) {
            return null;
        }

        if ($definition->matchOption($node, $message) !== null
            || $definition->target($node['id'], ScenarioDefinition::OUTPUT_FALLBACK) !== null) {
            return null;
        }

        return $node;
    }

    /**
     * Hand a message that matched none of the menu's own options to the AI
     * navigator. Returns true when the navigator answered for this turn;
     * false means «nothing was understood» — the caller falls back to
     * exactly what it did before the navigator existed.
     *
     * Both callers are now alike: neither has the menu on screen for this
     * turn, so whatever needs the menu shown says so itself.
     *
     * @param  array<string, mixed>  $node
     */
    private function routeFreeText(BotSession $session, Contact $contact, ScenarioDefinition $definition, array $node, InboundMessage $message): bool
    {
        $text = trim((string) $message->text);

        // Живое сообщение едет в блок целиком: фото с подписью «сдаю кран»
        // должно доехать вместе с фотографией, иначе объявление уходит на
        // модерацию без картинок, а бот просит их заново.
        $carried = $message;

        if ($text === '') {
            $text = (string) $this->transcribeVoice($session, $message);

            if ($text === '') {
                return false;
            }

            // Голосовое — исключение: дальше едет расшифровка, а не аудио.
            // Оно уже скачано и оплачено, и второй заход стоил бы второй
            // транскрипции (docs/modules/ai-assistant.md).
            $carried = InboundMessage::fromText($text);
        }

        $route = $this->menuRouter->route($session, $definition, $node, $carried);

        if ($route === null) {
            return false;
        }

        // Пол, общий для всех исходов: неуверенное прочтение — это ровно
        // то поведение, которое было до навигатора. Он стоит здесь, а не
        // только в реализации роутера, потому что движок и сам читает
        // уверенность (уверенный переход против предложения кнопкой), и
        // разные реализации MenuRouter не должны расходиться в том, что
        // считать пригодным к действию.
        if ($route->confidence === RouteConfidence::Low) {
            return false;
        }

        // Пять исходов, в которых человека никуда не ведут. Пороги
        // уверенности выше этого пола — за роутером: он решает, какому
        // намерению догадки мало.
        switch ($route->kind) {
            case MenuRouteKind::ServiceQuestion:
                $this->messenger->sendText($contact, $this->replyTexts->get(BotReplyKey::ServiceQuestion));
                $this->sendMenu($contact, $definition, $node);
                $session->save();

                return true;

            case MenuRouteKind::Acknowledgement:
                // Ничего не отправляем вовсе. «Спасибо» и «👍» закрывают
                // разговор — отвечать на них меню значит здороваться с
                // человеком, который прощается. Диалог честно завершается:
                // никто ничего не ждёт, а кнопки прошлого сообщения живут
                // дальше, и следующее сообщение по делу подхватится как
                // новый диалог — уже без приветствия.
                $this->endDialog($session);

                return true;

            case MenuRouteKind::Decline:
                $this->messenger->sendText($contact, $this->replyTexts->get(BotReplyKey::NavDeclined));
                $this->endDialog($session);

                return true;

            case MenuRouteKind::HumanHandoff:
                // Шаг не повторяется: человек и жалуется на то, что бот
                // водит его по кругу одним и тем же вопросом.
                $this->messenger->sendText($contact, $this->replyTexts->get(BotReplyKey::OperatorRequested));
                $session->save();

                return true;

            case MenuRouteKind::Greeting:
                // Поздоровались в ответ делом: показали, что бот умеет.
                // В накопленное приветствие не идёт — оно ничего не
                // описывает и только зашумило бы чтение остального. Но в
                // счёт повторов идёт: автоприветствие чужого бизнеса иначе
                // здоровалось бы с ботом вечно.
                $this->repeatStep($session, $contact, $definition, $node, null);

                return true;

            default:
                break;
        }

        // Человека уводят с шага — значит, всё, что он писал на нём и что
        // бот не понял по одному сообщению, едет вместе с ним. Иначе тот,
        // кто перечислял услуги по слову, начинал бы в ветке с чистого
        // листа и печатал их заново.
        $earlier = $session->menuStreak((string) $node['id'])['texts'] ?? [];
        $whole = implode("\n", [...$earlier, $text]);

        if ($earlier !== []) {
            $carried = $carried->withText($whole);
        }

        $this->clearMenuStreak($session);

        // Дальше обе развилки — уверенная и предположительная — расходятся по
        // одному признаку: у маршрута в раздел есть цель, у возврата к
        // анкете её нет (MenuRoute держит это инвариантом типа).
        $option = $route->kind === MenuRouteKind::Option ? $route->option : null;

        if ($route->confidence !== RouteConfidence::High) {
            $this->offerNavRoute($session, $contact, $definition, $option, $whole);

            return true;
        }

        if ($option !== null) {
            $this->executeOptionRoute($session, $contact, $definition, $option, $carried);

            return true;
        }

        $this->resumeFromPaused($session, $contact, $definition, $carried);

        return true;
    }

    /**
     * Middling confidence is not enough to move the contact, but too much to
     * throw away: the bot names the destination it read and offers a single
     * button. Until it is pressed the dialog stays exactly where it was.
     *
     * @param  array{node_id: string, option_id: string}|null  $option  null — предложение вернуться к прерванной анкете
     */
    private function offerNavRoute(BotSession $session, Contact $contact, ScenarioDefinition $definition, ?array $option, string $text): void
    {
        $title = $option === null ? self::NAV_CONFIRM_RESUME_TITLE : self::NAV_CONFIRM_GO_TITLE;

        // Отправка идёт первой, запись — только после неё: сорвавшаяся
        // отправка ретраится джобом вебхука целиком, и предложение,
        // записанное упавшей попыткой, затёрло бы то, которое дойдёт со
        // следующей. str_replace, а не sprintf: текст правит оператор, и
        // одинокий процент в его редакции («100% техники») уронил бы
        // sprintf с ValueError — контакт получил бы тишину.
        $this->messenger->sendButtons(
            $contact,
            $option === null
                ? $this->replyTexts->get(BotReplyKey::NavResumeOffer)
                : str_replace('%s', $this->optionTitle($definition, $option['option_id']), $this->replyTexts->get(BotReplyKey::NavRouteOffer)),
            [['id' => self::NAV_CONFIRM, 'title' => $title]],
        );

        $session->state = ['nav_proposal' => [
            'route' => $option === null ? self::NAV_ROUTE_RESUME : ScenarioDefinition::optionOutput($option['option_id']),
            'text' => $text,
            'title' => $title,
            'expires_at' => now()->addMinutes(self::NAV_PROPOSAL_TTL_MINUTES)->toIso8601String(),
        ]];
        $session->save();
    }

    /**
     * Answer the navigator's offer. Returns true when it fully handled the
     * message; false to let the normal per-block flow run — which is also
     * how an offer that no longer applies gets out of the way.
     *
     * @param  array<string, mixed>|null  $node
     */
    private function handleNavProposal(BotSession $session, Contact $contact, BotScenario $scenario, ScenarioDefinition $definition, ?array $node, ?BotNodeType $type, InboundMessage $message): bool
    {
        $proposal = $session->state['nav_proposal'] ?? null;

        if (! is_array($proposal)) {
            return false;
        }

        // Anything but a confirmation outdates the offer: the contact went on
        // talking. If the new message matches no button either, the navigator
        // is asked again — that is a new event, not this one.
        if (! $this->confirmsNavRoute($proposal, $message)) {
            $session->state = null;
            $session->save();

            return false;
        }

        $session->state = null;

        // Too late to act on — the same honest answer a button from an
        // older version gets: a text and the current step repeated. Handled
        // right here rather than left to the flow below, because a typed
        // title never reaches button routing at all: it carries no replyId,
        // and the navigator would be paid to classify a word it has just
        // recognized itself.
        if ($this->navProposalExpired($proposal)) {
            $this->handleStaleButton($session, $contact, $scenario, $definition, $node, $type);

            return true;
        }

        $carried = InboundMessage::fromText((string) ($proposal['text'] ?? ''));
        $route = (string) ($proposal['route'] ?? '');

        if ($route === self::NAV_ROUTE_RESUME) {
            $this->resumeFromPaused($session, $contact, $definition, $carried);

            return true;
        }

        $owner = $definition->optionOwner(Str::after($route, 'option:'));

        // Republished away between the offer and the press — the same honest
        // answer a button from an older version gets.
        if ($owner === null) {
            $this->handleStaleButton($session, $contact, $scenario, $definition, $node, $type);

            return true;
        }

        $this->executeOptionRoute($session, $contact, $definition, $owner, $carried);

        return true;
    }

    /**
     * Пресс по кнопке предложения или набранный её титул — вся система
     * так и устроена: набрать название кнопки значит нажать её.
     *
     * Путь титула открыт только набранному тексту: WhatsApp кладёт в текст
     * человеческий титул нажатой кнопки, поэтому кнопка графа, названная
     * тем же словом («Продолжить»), иначе подтверждала бы предложение
     * вместо того, чтобы отработать саму себя.
     *
     * @param  array<string, mixed>  $proposal
     */
    private function confirmsNavRoute(array $proposal, InboundMessage $message): bool
    {
        if (filled($message->replyId)) {
            return $message->replyId === self::NAV_CONFIRM;
        }

        $title = mb_strtolower(trim((string) ($proposal['title'] ?? '')));

        return $title !== '' && mb_strtolower(trim((string) $message->text)) === $title;
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function navProposalExpired(array $proposal): bool
    {
        $expiresAt = $proposal['expires_at'] ?? null;

        if (! is_string($expiresAt)) {
            return true;
        }

        try {
            return Carbon::parse($expiresAt)->isPast();
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Send the contact down a menu option they never pressed, carrying what
     * they actually wrote. When the branch waits at an AI block, that text is
     * handed straight to it: in the chat the block greets first (from start)
     * and answers the text second, so nothing has to be typed twice. A branch
     * without an AI block simply plays out — the text has nowhere to go and
     * the navigator is not asked about it again.
     *
     * @param  array{node_id: string, option_id: string}  $owner
     */
    private function executeOptionRoute(BotSession $session, Contact $contact, ScenarioDefinition $definition, array $owner, InboundMessage $carried): void
    {
        $pausedNode = $this->pausedNode($session, $definition);

        // Ветка опции упирается в тот самый блок, на котором стоит
        // прерванная анкета: пройти её обычным маршрутом значило бы стереть
        // снапшот и завести вторую анкету рядом с первой. Классификатор
        // делает ту же проверку для маршрута, который отдаёт; здесь она
        // повторена, потому что сохранённое предложение исполняется ходом
        // позже и уже против опубликованного сейчас графа.
        if ($pausedNode !== null
            && $definition->resolveTarget($owner['node_id'], ScenarioDefinition::optionOutput($owner['option_id'])) === ($pausedNode['id'] ?? null)) {
            $this->resumeFromPaused($session, $contact, $definition, $carried);

            return;
        }

        // One walk, not two: the block itself answers by the carried text
        // instead of first inviting the contact to say what they just said.
        $this->routeToOption($session, $contact, $definition, $owner, $carried);
    }

    /**
     * The block of an interrupted questionnaire that is still worth
     * returning to, or null. The snapshot is re-validated against the
     * definition published right now, not the one it was taken under: a
     * republication since could have moved or reshaped that block, and
     * restoring answers into a question that no longer asks them is worse
     * than starting over. The TTL itself is BotSession::pausedState()'s.
     *
     * @return array<string, mixed>|null
     */
    private function pausedNode(BotSession $session, ScenarioDefinition $definition): ?array
    {
        $snapshot = $session->pausedState();
        $node = $snapshot === null ? null : $definition->node($snapshot['node_id']);

        if ($snapshot === null
            || $node === null
            || $definition->nodeType($node) !== BotNodeType::AiInput
            || $definition->nodeFingerprint($node) !== $snapshot['fingerprint']) {
            return null;
        }

        return $node;
    }

    /**
     * Put the contact back on the questionnaire they walked out of, with the
     * working memory the snapshot holds.
     */
    private function resumeFromPaused(BotSession $session, Contact $contact, ScenarioDefinition $definition, InboundMessage $carried): void
    {
        $snapshot = $session->pausedState();
        $node = $this->pausedNode($session, $definition);

        if ($snapshot === null || $node === null) {
            $this->dropPausedState($session, $contact, $definition);

            return;
        }

        $session->paused_state = null;
        $session->state = $snapshot['state'];
        $this->waitAt($session, (string) $node['id'], $snapshot['fingerprint']);

        // «Всё написанное на месте» обещало бы продолжение, которого не
        // будет: черновик прерванной анкеты успел уйти из-под неё (его
        // отправили на проверку из кабинета, опубликовали или удалили из
        // админки), и коллектор сейчас завершит блок честным статусом.
        if (! SupplierListingCollector::draftMovedOn((array) ($snapshot['state'] ?? []), $session->contact_id)) {
            $this->messenger->sendText($contact, $this->replyTexts->get(BotReplyKey::NavResumed));
        }

        // Никакого start(): приветствие блока человек уже слышал, а
        // коллектор сам заэкстрактит написанное и задаст следующий вопрос.
        $this->resumeAi($session, $contact, $definition, $node, $carried);
    }

    /**
     * Nothing left to resume — say so by simply showing the step the contact
     * is standing on, exactly as an unrecognized answer does.
     */
    private function dropPausedState(BotSession $session, Contact $contact, ScenarioDefinition $definition): void
    {
        $session->paused_state = null;
        $session->state = null;

        $node = $definition->node($session->current_node_id);

        if ($node !== null && $this->isMenu($definition, $node)) {
            $this->repeatStep($session, $contact, $definition, $node, null);

            return;
        }

        $session->save();
    }

    /**
     * Voice at a menu step, resolved with the very services the AI entry
     * point uses — so the transcription is journalled once, in the same
     * place, at the same price. A failure at any step reads as «no text at
     * all»: the menu repeats, exactly as it did before the navigator.
     */
    private function transcribeVoice(BotSession $session, InboundMessage $message): ?string
    {
        if (! $message->isVoice()) {
            return null;
        }

        // На кнопочном шаге голосовое стало стоить транскрипции плюс вызова
        // классификатора, а 24-часовое окно открывает себе любой номер,
        // написавший однажды. Потолок держится по контакту; превышение
        // читается ровно как сбой распознавания — повтор меню.
        $limiterKey = self::VOICE_RATE_LIMIT_KEY.$session->contact_id;

        if (RateLimiter::tooManyAttempts($limiterKey, self::VOICE_RATE_LIMIT_ATTEMPTS)) {
            Log::warning('Voice messages at a menu step hit the hourly limit; the menu repeats instead.', [
                'bot_session_id' => $session->id,
                'contact_id' => $session->contact_id,
            ]);

            return null;
        }

        RateLimiter::hit($limiterKey, self::VOICE_RATE_LIMIT_SECONDS);

        try {
            $download = $this->mediaDownloader->download((string) $message->mediaId);

            $transcription = trim($this->transcriber->transcribe($download['contents'], $download['mime_type'], [
                'contact_id' => $session->contact_id,
                'bot_session_id' => $session->id,
            ]));
        } catch (OutboundRequestBlocked $e) {
            // A local block is not an unreadable recording: repeating the
            // menu would hide it behind the ordinary download failure.
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Voice message at a menu step could not be downloaded or transcribed.', [
                'bot_session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $transcription === '' ? null : $transcription;
    }

    /**
     * The human title of an option anywhere in the graph — what the bot
     * names when it offers to take the contact there.
     */
    private function optionTitle(ScenarioDefinition $definition, string $optionId): string
    {
        return $definition->menuOptions()[$optionId]['title'] ?? '';
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function isMenu(ScenarioDefinition $definition, array $node): bool
    {
        return in_array($definition->nodeType($node), [BotNodeType::ButtonMenu, BotNodeType::ListMenu], true);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function resumeAi(BotSession $session, Contact $contact, ScenarioDefinition $definition, array $node, InboundMessage $message): void
    {
        if ($this->aiAssistant->resume($session, $node, $message) === AiOutcome::Completed) {
            $this->advance($session, $contact, $definition, $definition->target($node['id'], ScenarioDefinition::OUTPUT_CONTINUE));

            return;
        }

        $session->save();
    }

    /**
     * Answer a message the bot could not act on by showing the step again
     * — but only for as long as showing it is still an answer.
     *
     * $remember is the text to read together with the rest of the run next
     * time, or null for a turn that carries nothing worth re-reading: a
     * bare greeting, a voice that would not transcribe, a resume offer
     * that went stale. Those still count towards the run — the contact is
     * looking at the same menu either way — they simply add nothing to
     * what the navigator gets to read.
     *
     * @param  array<string, mixed>  $node
     */
    private function repeatStep(BotSession $session, Contact $contact, ScenarioDefinition $definition, array $node, ?string $remember): void
    {
        $nodeId = (string) $node['id'];
        $streak = $session->menuStreak($nodeId) ?? ['count' => 0, 'texts' => []];
        $count = $streak['count'] + 1;
        $texts = $streak['texts'];

        if ($remember !== null && trim($remember) !== '') {
            $texts[] = trim($remember);
            $texts = array_slice($texts, -BotSession::MENU_STREAK_TEXTS);
        }

        $session->menu_streak = ['node_id' => $nodeId, 'count' => $count, 'texts' => $texts];

        if ($count <= self::MENU_REPEAT_LIMIT) {
            $this->sendMenu($contact, $definition, $node);
        } elseif ($count === self::MENU_REPEAT_LIMIT + 1) {
            // Said once, and only once. Repeating this would be the same
            // loop wearing a different sentence.
            $this->messenger->sendText($contact, $this->replyTexts->get(BotReplyKey::MenuStuck));
        }

        $session->save();
    }

    /**
     * The run is over — the contact was understood, or moved on.
     */
    private function clearMenuStreak(BotSession $session): void
    {
        if ($session->menu_streak !== null) {
            $session->menu_streak = null;
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function sendMenu(Contact $contact, ScenarioDefinition $definition, array $node): void
    {
        $text = (string) ($node['text'] ?? '');
        $options = $definition->options($node);

        if ($definition->nodeType($node) === BotNodeType::ButtonMenu) {
            $this->messenger->sendButtons($contact, $text, $options);

            return;
        }

        $this->messenger->sendList($contact, $text, (string) ($node['button'] ?? self::DEFAULT_LIST_BUTTON), $options);
    }

    /**
     * Which Start output a fresh dialog follows: the optional «Повторное
     * обращение» output for a contact the bot has met before (and only
     * when that output is wired), otherwise the default greeting.
     *
     * «Met before» is two things, not one. The session knows the contact
     * reached a waiting step in some earlier dialog. The contact knows
     * something the session cannot: the renewal poll, the moderation
     * verdict and the customer request all run as isolated scenario runs
     * and never create a session row, so a supplier who has been getting
     * messages for a month had no session at all and was introduced to the
     * service from scratch — the incident behind this check.
     *
     * Order matters for cost: the wiring check is free, the session's flag
     * is already loaded, and only a contact with neither reaches the
     * journal — which is nobody on the common path.
     */
    private function startOutput(BotSession $session, Contact $contact, ScenarioDefinition $definition, string $startId): string
    {
        if ($definition->target($startId, ScenarioDefinition::OUTPUT_RETURNING) === null) {
            return ScenarioDefinition::OUTPUT_CONTINUE;
        }

        if ($session->hasCompletedDialog() || $contact->hasBotHistory()) {
            return ScenarioDefinition::OUTPUT_RETURNING;
        }

        return ScenarioDefinition::OUTPUT_CONTINUE;
    }

    /**
     * Parks the dialog at a waiting block. Also marks the greeting as
     * shown for good: reaching any waiting step — not only fully finishing
     * the dialog — puts the contact on the «Повторное обращение» path for
     * every dialog that follows.
     */
    private function waitAt(BotSession $session, string $nodeId, string $fingerprint): void
    {
        // Parking somewhere else is progress: whatever the contact could
        // not get past, they are past it now.
        if ($session->menuStreak($nodeId) === null) {
            $this->clearMenuStreak($session);
        }

        $session->current_node_id = $nodeId;
        $session->current_node_fingerprint = $fingerprint;
        $session->last_dialog_ended_at = now();
        $session->save();
    }

    private function endDialog(BotSession $session): void
    {
        $this->clearMenuStreak($session);
        $session->current_node_id = null;
        $session->current_node_fingerprint = null;
        $session->last_dialog_ended_at = now();
        $session->save();
    }
}
