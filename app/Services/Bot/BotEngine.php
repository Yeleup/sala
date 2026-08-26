<?php

namespace App\Services\Bot;

use App\Enums\AiOutcome;
use App\Enums\BotNodeType;
use App\Enums\BotReplyKey;
use App\Enums\MenuRouteKind;
use App\Enums\RouteConfidence;
use App\Models\BotScenario;
use App\Models\BotSession;
use App\Models\Contact;
use App\Services\Ai\CtaLinkBuilder;
use App\Services\Ai\VoiceTranscriber;
use App\Services\DereuMediaDownloader;
use App\Services\DereuMessenger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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

    /** The "resume the interrupted questionnaire" destination of an offer. */
    private const string NAV_ROUTE_RESUME = 'resume';

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
    ) {}

    public function handle(Contact $contact, InboundMessage $message): void
    {
        // Buttons of scenario runs carry flow:{token}:{option} payloads
        // and route to their own run — they never enter the main dialog.
        if ($this->runReplies->handle($contact, $message)) {
            return;
        }

        // Replies to built-in proactive notifications (the moderation
        // verdict button, plus legacy buttons sent before the flows moved
        // into scenarios) can also arrive at any step.
        if ($this->notificationReplies->handle($contact, $message)) {
            return;
        }

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
            $this->restart($session, $contact, $scenario, $definition);
            $this->routeFirstMessage($session, $contact, $definition, $message);

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
     */
    private function routeToOption(BotSession $session, Contact $contact, ScenarioDefinition $definition, array $owner): void
    {
        // Jumping away from an AI block abandons its working memory.
        if ($session->state !== null) {
            $session->state = null;
        }

        $target = $definition->target($owner['node_id'], ScenarioDefinition::optionOutput($owner['option_id']));

        $this->advance($session, $contact, $definition, $target);
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
        $session->bot_scenario_id = $scenario->id;
        $session->scenario_version = $scenario->published_version;
        $session->updated_at = now();

        $this->advance($session, $contact, $definition, $definition->startNodeId());
    }

    /**
     * Walk the graph from the given node: send block messages, follow
     * "continue" transitions, stop at the first block that waits for input.
     *
     * @param  array<string, mixed>|null  $node
     */
    private function advance(BotSession $session, Contact $contact, ScenarioDefinition $definition, ?string $nodeId): void
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
                    $nodeId = $definition->target($node['id'], $this->startOutput($session, $definition, $node['id']));
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
                    $this->sendMenu($contact, $definition, $node);
                    $this->waitAt($session, $node['id'], $definition->nodeFingerprint($node));

                    return;

                case BotNodeType::AiInput:
                    $this->waitAt($session, $node['id'], $definition->nodeFingerprint($node));

                    if ($this->aiAssistant->start($session, $node) !== AiOutcome::Completed) {
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
            $this->advance($session, $contact, $definition, $definition->target($node['id'], ScenarioDefinition::optionOutput($optionId)));

            return;
        }

        $fallbackTarget = $definition->target($node['id'], ScenarioDefinition::OUTPUT_FALLBACK);

        if ($fallbackTarget !== null) {
            $this->advance($session, $contact, $definition, $fallbackTarget);

            return;
        }

        // Ничего из графа не подошло, «Любая другая фраза» не подключена —
        // последний шанс понять сказанное есть у ИИ-навигатора.
        if ($this->routeFreeText($session, $contact, $definition, $node, $message, menuJustSent: false)) {
            return;
        }

        // Не понял и он — бот повторяет текущий шаг.
        $this->sendMenu($contact, $definition, $node);
        $session->save();
    }

    /**
     * The message that opened the dialog says what the contact wants just as
     * much as the next one does — the greeting and the menu have already
     * gone out by the time we get here, so the navigator either moves the
     * contact on or stays silent. Only a typed message is worth asking
     * about: a press is a destination in itself, and voice is left to the
     * ordinary menu turn.
     */
    private function routeFirstMessage(BotSession $session, Contact $contact, ScenarioDefinition $definition, InboundMessage $message): void
    {
        if (filled($message->replyId) || trim((string) $message->text) === '') {
            return;
        }

        $node = $definition->node($session->current_node_id);

        if ($node === null || ! $this->isMenu($definition, $node)) {
            return;
        }

        $this->routeFreeText($session, $contact, $definition, $node, $message, menuJustSent: true);
    }

    /**
     * Hand a message that matched none of the menu's own options to the AI
     * navigator. Returns true when the navigator answered for this turn;
     * false means «nothing was understood» — the caller falls back to
     * exactly what it did before the navigator existed.
     *
     * $menuJustSent tells the navigator the menu is already the last thing
     * in the chat (the dialog has only just started), so a service question
     * is answered without repeating it.
     *
     * @param  array<string, mixed>  $node
     */
    private function routeFreeText(BotSession $session, Contact $contact, ScenarioDefinition $definition, array $node, InboundMessage $message, bool $menuJustSent): bool
    {
        $text = trim((string) $message->text);

        if ($text === '') {
            $text = (string) $this->transcribeVoice($session, $message);

            if ($text === '') {
                return false;
            }
        }

        $carried = InboundMessage::fromText($text);
        $route = $this->menuRouter->route($session, $definition, $node, $carried);

        if ($route === null) {
            return false;
        }

        // Про сам сервис бот отвечает сам и остаётся на текущем шаге —
        // это не навигация, уводить человека некуда.
        if ($route->kind === MenuRouteKind::ServiceQuestion) {
            $this->messenger->sendText($contact, $this->replyTexts->get(BotReplyKey::ServiceQuestion));

            if (! $menuJustSent) {
                $this->sendMenu($contact, $definition, $node);
            }

            $session->save();

            return true;
        }

        // Дальше обе развилки — уверенная и предположительная — расходятся по
        // одному признаку: у маршрута в раздел есть цель, у возврата к
        // анкете её нет (MenuRoute держит это инвариантом типа).
        $option = $route->kind === MenuRouteKind::Option ? $route->option : null;

        if ($route->confidence !== RouteConfidence::High) {
            $this->offerNavRoute($session, $contact, $definition, $option, $text);

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

        $session->state = ['nav_proposal' => [
            'route' => $option === null ? self::NAV_ROUTE_RESUME : ScenarioDefinition::optionOutput($option['option_id']),
            'text' => $text,
            'title' => $title,
            'expires_at' => now()->addMinutes(self::NAV_PROPOSAL_TTL_MINUTES)->toIso8601String(),
        ]];
        $session->save();

        $this->messenger->sendButtons(
            $contact,
            $option === null
                ? $this->replyTexts->get(BotReplyKey::NavResumeOffer)
                : sprintf($this->replyTexts->get(BotReplyKey::NavRouteOffer), $this->optionTitle($definition, $option['option_id'])),
            [['id' => self::NAV_CONFIRM, 'title' => $title]],
        );
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

        // Too late to act on: «nav_confirm» belongs to no block, so the flow
        // below lands on the existing stale-button path — an honest text and
        // the current step repeated.
        if ($this->navProposalExpired($proposal)) {
            $session->save();

            return false;
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
     * @param  array<string, mixed>  $proposal
     */
    private function confirmsNavRoute(array $proposal, InboundMessage $message): bool
    {
        if ($message->replyId === self::NAV_CONFIRM) {
            return true;
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
        $this->routeToOption($session, $contact, $definition, $owner);

        $node = $definition->node($session->current_node_id);

        if ($node !== null && $definition->nodeType($node) === BotNodeType::AiInput) {
            $this->resumeAi($session, $contact, $definition, $node, $carried);
        }
    }

    /**
     * Put the contact back on the questionnaire they walked out of, with the
     * working memory the snapshot holds. The snapshot is re-validated against
     * the definition published right now, not the one it was taken under: a
     * republication between the offer and the confirmation could have moved
     * or reshaped that block, and restoring answers into a question that no
     * longer asks them is worse than starting over.
     */
    private function resumeFromPaused(BotSession $session, Contact $contact, ScenarioDefinition $definition, InboundMessage $carried): void
    {
        $snapshot = $session->pausedState();
        $node = $snapshot === null ? null : $definition->node($snapshot['node_id']);

        if ($snapshot === null
            || $node === null
            || $definition->nodeType($node) !== BotNodeType::AiInput
            || $definition->nodeFingerprint($node) !== $snapshot['fingerprint']) {
            $this->dropPausedState($session, $contact, $definition);

            return;
        }

        $session->paused_state = null;
        $session->state = $snapshot['state'];
        $this->waitAt($session, (string) $node['id'], $snapshot['fingerprint']);

        $this->messenger->sendText($contact, $this->replyTexts->get(BotReplyKey::NavResumed));

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
            $this->sendMenu($contact, $definition, $node);
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

        try {
            $download = $this->mediaDownloader->download((string) $message->mediaId);

            $transcription = trim($this->transcriber->transcribe($download['contents'], $download['mime_type'], [
                'contact_id' => $session->contact_id,
                'bot_session_id' => $session->id,
            ]));
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
     * обращение» output for a contact who already reached at least one
     * waiting step before — the greeting shows only once — (and only when
     * that output is wired), otherwise the default greeting.
     */
    private function startOutput(BotSession $session, ScenarioDefinition $definition, string $startId): string
    {
        if ($session->hasCompletedDialog()
            && $definition->target($startId, ScenarioDefinition::OUTPUT_RETURNING) !== null) {
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
        $session->current_node_id = $nodeId;
        $session->current_node_fingerprint = $fingerprint;
        $session->last_dialog_ended_at = now();
        $session->save();
    }

    private function endDialog(BotSession $session): void
    {
        $session->current_node_id = null;
        $session->current_node_fingerprint = null;
        $session->last_dialog_ended_at = now();
        $session->save();
    }
}
