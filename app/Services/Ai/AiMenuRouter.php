<?php

namespace App\Services\Ai;

use App\Ai\Agents\MenuRouteAgent;
use App\Enums\AiOperationType;
use App\Enums\BotNodeType;
use App\Enums\ListingKind;
use App\Enums\MenuIntent;
use App\Enums\RouteConfidence;
use App\Models\BotSession;
use App\Services\Ai\Audit\AiAudit;
use App\Services\Bot\InboundMessage;
use App\Services\Bot\MenuRoute;
use App\Services\Bot\MenuRouter;
use App\Services\Bot\ScenarioDefinition;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The AI navigator's classifier (docs/modules/ai-assistant.md): turns a
 * message typed at a button/list menu node that matched none of its own
 * options into a typed MenuRoute, with one cheap structured model call.
 *
 * Guarded so the model is never asked about what code can already decide
 * (blank text, a lone digit — a missed positional pick) and never trusted
 * past what the caller can safely act on (low confidence, a hallucinated
 * resume without a real candidate behind it). Every failure — nothing
 * worth classifying, an unavailable provider, an unusable answer —
 * resolves to null, which by the MenuRouter contract means «behave exactly
 * as today»: a wrong guess would silently drop the contact into the wrong
 * branch, which is worse than the menu repeating.
 */
class AiMenuRouter implements MenuRouter
{
    /** How much of the contact's message the classifier is shown. */
    private const int MAX_MESSAGE_CHARS = 500;

    public function __construct(private readonly AiAudit $audit) {}

    /**
     * @param  array<string, mixed>  $node
     */
    public function route(BotSession $session, ScenarioDefinition $definition, array $node, InboundMessage $message): ?MenuRoute
    {
        $text = trim((string) $message->text);

        if ($text === '' || ctype_digit($text)) {
            return null;
        }

        $targets = $this->targets($definition);
        $resumeCandidate = $this->resumeCandidate($session, $definition);

        try {
            $result = $this->audit->run(
                AiOperationType::MenuRouting,
                fn (): array => (new MenuRouteAgent($targets, $this->resumeLabel($resumeCandidate)))
                    ->prompt($this->prompt($text, $node, $session->menuStreak($node['id'] ?? null)['texts'] ?? []))
                    ->toArray(),
                [
                    'contact_id' => $session->contact_id,
                    'bot_session_id' => $session->id,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('Menu routing failed; the engine falls back to repeating the menu.', [
                'bot_session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->toRoute($result, $definition, $resumeCandidate);
    }

    /**
     * @return array<string, string>
     */
    private function targets(ScenarioDefinition $definition): array
    {
        $targets = [];

        foreach ($definition->menuOptions() as $optionId => $owner) {
            $targets['option:'.$optionId] = sprintf('«%s» → «%s»', $owner['context'], $owner['title']);
        }

        return $targets;
    }

    /**
     * The interrupted questionnaire the contact may be asking to resume —
     * only while it is still valid to offer: pausedState() already applies
     * the TTL, and here that snapshot's node must still exist, still be an
     * AI block, and still have the same shape it had when the snapshot was
     * taken (nodeFingerprint — mirrors the engine's own staleness check on
     * current_node_fingerprint). An invalid snapshot is left in the column
     * as-is: clearing it is the engine's job (task 4), not the
     * classifier's.
     *
     * @return array{node_id: string, fingerprint: string, state: array<string, mixed>, saved_at: string}|null
     */
    private function resumeCandidate(BotSession $session, ScenarioDefinition $definition): ?array
    {
        $paused = $session->pausedState();

        if ($paused === null) {
            return null;
        }

        $node = $definition->node($paused['node_id']);

        if ($node === null || $definition->nodeType($node) !== BotNodeType::AiInput) {
            return null;
        }

        return $definition->nodeFingerprint($node) === $paused['fingerprint'] ? $paused : null;
    }

    /**
     * @param  array{node_id: string, fingerprint: string, state: array<string, mixed>, saved_at: string}|null  $resumeCandidate
     */
    private function resumeLabel(?array $resumeCandidate): ?string
    {
        if ($resumeCandidate === null) {
            return null;
        }

        $kind = (string) ($resumeCandidate['state']['kind'] ?? '');
        $label = ListingKind::tryFrom($kind)?->label() ?? $kind;

        return "вернуться к прерванной анкете ({$label})";
    }

    /**
     * The node's own text is context the operator wrote; the message and
     * whatever came before it on this same step are data the contact
     * wrote. Each goes in as its own section, and every piece of contact
     * text is fenced so a multi-line message cannot pass itself off as one
     * more instruction — the agent's own rules say the same in words.
     *
     * The earlier messages matter because people write one thought across
     * several messages: «Мотор», «Воздушные», «Ходовка» name no section
     * one at a time and name one unmistakably read together.
     *
     * Text is cut to a sane length: a menu step can receive a minutes-long
     * voice transcription, and classifying «which section is this» needs
     * the beginning of it, not all of it.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $earlier
     */
    private function prompt(string $text, array $node, array $earlier = []): string
    {
        $sections = [];
        $menuText = trim((string) ($node['text'] ?? ''));

        if ($menuText !== '') {
            $sections[] = "Текст текущего меню: «{$menuText}»";
        }

        if ($earlier !== []) {
            $previous = implode("\n", array_map($this->clip(...), $earlier));
            $sections[] = "Предыдущие сообщения человека на этом же шаге (читай их вместе с последним):\n---\n{$previous}\n---";
        }

        $sections[] = "Сообщение человека:\n---\n{$this->clip($text)}\n---";

        return implode("\n\n", $sections);
    }

    private function clip(string $text): string
    {
        return mb_strlen($text) > self::MAX_MESSAGE_CHARS
            ? mb_substr($text, 0, self::MAX_MESSAGE_CHARS).'…'
            : $text;
    }

    /**
     * Turn the model's raw answer into a MenuRoute, or null wherever the
     * answer cannot be trusted or acted on.
     *
     * One rule runs across every intent and is the reason new intents can
     * be added without risk: **low confidence means exactly today's
     * behaviour**. Whatever the model thought it read, an unsure reading
     * repeats the step, which is what the step did before the navigator
     * existed.
     *
     * Above that floor each intent carries its own bar, set by how much a
     * mistake costs the person:
     *
     * - Acknowledgement, Decline and Greeting need High. Silence and
     *   goodbyes are the answers a person cannot argue with — nothing on
     *   screen tells them what to do next — so a guess is not enough.
     * - HumanHandoff accepts Medium. It is the one intent where repeating
     *   the step is the very thing being complained about, and promising
     *   an operator to someone who did not ask costs little: the operator
     *   really does see the conversation.
     *
     * @param  array<string, mixed>  $result
     * @param  array{node_id: string, fingerprint: string, state: array<string, mixed>, saved_at: string}|null  $resumeCandidate
     */
    private function toRoute(array $result, ScenarioDefinition $definition, ?array $resumeCandidate): ?MenuRoute
    {
        $intent = MenuIntent::fromExtraction($result['intent'] ?? null);
        $confidence = RouteConfidence::fromExtraction($result['confidence'] ?? null);

        if ($confidence === RouteConfidence::Low) {
            return null;
        }

        $sure = $confidence === RouteConfidence::High;

        return match ($intent) {
            MenuIntent::Navigate => $this->toOption($result, $definition, $resumeCandidate, $confidence),
            // The schema drops «resume» from the enum when there is no
            // candidate, but a misbehaving or faked provider is not bound
            // by the schema — so the check is repeated here in code.
            MenuIntent::Resume => $resumeCandidate === null ? null : MenuRoute::toResume($confidence),
            MenuIntent::ServiceQuestion => MenuRoute::toServiceQuestion($confidence),
            MenuIntent::Acknowledgement => $sure ? MenuRoute::toAcknowledgement($confidence) : null,
            MenuIntent::Decline => $sure ? MenuRoute::toDecline($confidence) : null,
            MenuIntent::HumanHandoff => MenuRoute::toHumanHandoff($confidence),
            MenuIntent::Greeting => $sure ? MenuRoute::toGreeting($confidence) : null,
            MenuIntent::Unclear => null,
        };
    }

    /**
     * The section a «navigate» answer named, or null when it named none —
     * a model that says «navigate» and then «none» has contradicted
     * itself, and a contradiction is not something to act on.
     *
     * @param  array<string, mixed>  $result
     * @param  array{node_id: string, fingerprint: string, state: array<string, mixed>, saved_at: string}|null  $resumeCandidate
     */
    private function toOption(array $result, ScenarioDefinition $definition, ?array $resumeCandidate, RouteConfidence $confidence): ?MenuRoute
    {
        $option = (string) ($result['option'] ?? 'none');

        if ($option === 'none') {
            return null;
        }

        $owner = $definition->optionOwner(Str::after($option, 'option:'));

        if ($owner === null) {
            return null;
        }

        // The chosen option leads into the very node the paused
        // questionnaire is waiting at: routing it as an ordinary Option
        // would start a second, duplicate questionnaire next to the one
        // already in progress. Resuming the existing one is what the
        // contact actually wants — code decides this, not the model.
        // resolveTarget(), not target(): an operator is free to put a text
        // block between the option and the questionnaire, and the engine
        // walks straight through it.
        if ($resumeCandidate !== null
            && $definition->resolveTarget($owner['node_id'], ScenarioDefinition::optionOutput($owner['option_id'])) === $resumeCandidate['node_id']) {
            return MenuRoute::toResume($confidence);
        }

        return MenuRoute::toOption($owner, $confidence);
    }
}
