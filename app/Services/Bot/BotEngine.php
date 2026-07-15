<?php

namespace App\Services\Bot;

use App\Enums\AiOutcome;
use App\Enums\BotNodeType;
use App\Models\BotScenario;
use App\Models\BotSession;
use App\Models\Contact;
use App\Services\Ai\CtaLinkBuilder;
use App\Services\DereuMessenger;

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

    private const string STALE_BUTTON_NOTICE = 'Эта кнопка из прежней версии бота и больше не действует.';

    public function __construct(
        private readonly DereuMessenger $messenger,
        private readonly AiAssistant $aiAssistant,
        private readonly ScenarioRunReplyHandler $runReplies,
        private readonly NotificationReplyHandler $notificationReplies,
        private readonly CtaLinkBuilder $links,
    ) {}

    public function handle(Contact $contact, InboundMessage $message): void
    {
        // Buttons of scenario runs carry flow:{token}:{option} payloads
        // and route to their own run — they never enter the main dialog.
        if ($this->runReplies->handle($contact, $message)) {
            return;
        }

        // Replies to legacy proactive notifications (buttons sent before
        // the flows moved into scenarios) can also arrive at any step.
        if ($this->notificationReplies->handle($contact, $message)) {
            return;
        }

        $scenario = BotScenario::main();
        $definition = $scenario?->publishedDefinition();

        if ($scenario === null || $definition === null) {
            return;
        }

        $session = BotSession::query()->firstOrNew(['contact_id' => $contact->id]);

        if ($this->startsNewDialog($session, $scenario, $definition)) {
            $this->restart($session, $contact, $scenario, $definition);

            return;
        }

        // Soft update: the awaited block survived republication, so the
        // contact continues on the new version without losing the step.
        $session->scenario_version = $scenario->published_version;
        $session->updated_at = now();

        $node = $definition->node($session->current_node_id);
        $type = $definition->nodeType($node);

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
        $this->messenger->sendText($contact, self::STALE_BUTTON_NOTICE);

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
                        (string) ($node['text'] ?? '') ?: 'Откройте кабинет — там ваши объявления, статусы и причины отклонения.',
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

        // «Любая другая фраза» не подключена — бот повторяет текущий шаг.
        $this->sendMenu($contact, $definition, $node);
        $session->save();
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
     * обращение» output for a contact who already finished a dialog before
     * (and only when that output is wired), otherwise the default greeting.
     */
    private function startOutput(BotSession $session, ScenarioDefinition $definition, string $startId): string
    {
        if ($session->hasCompletedDialog()
            && $definition->target($startId, ScenarioDefinition::OUTPUT_RETURNING) !== null) {
            return ScenarioDefinition::OUTPUT_RETURNING;
        }

        return ScenarioDefinition::OUTPUT_CONTINUE;
    }

    private function waitAt(BotSession $session, string $nodeId, string $fingerprint): void
    {
        $session->current_node_id = $nodeId;
        $session->current_node_fingerprint = $fingerprint;
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
