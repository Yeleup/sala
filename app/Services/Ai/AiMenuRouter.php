<?php

namespace App\Services\Ai;

use App\Ai\Agents\MenuRouteAgent;
use App\Enums\AiOperationType;
use App\Enums\BotNodeType;
use App\Enums\ListingKind;
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
                    ->prompt($this->prompt($text, $node))
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
     * @param  array<string, mixed>  $node
     */
    private function prompt(string $text, array $node): string
    {
        $menuText = trim((string) ($node['text'] ?? ''));

        return $menuText === ''
            ? "Сообщение человека: {$text}"
            : "Текст текущего меню: «{$menuText}»\n\nСообщение человека: {$text}";
    }

    /**
     * Turn the model's raw answer into a MenuRoute, or null wherever the
     * answer cannot be trusted or acted on: no reading at all ("none"), too
     * unsure (low confidence), or a "resume" the model produced without a
     * real candidate behind it — the schema already excludes "resume" from
     * the enum whenever there is no candidate, but a misbehaving or faked
     * provider is not bound by the schema, so the check is repeated here in
     * code.
     *
     * @param  array<string, mixed>  $result
     * @param  array{node_id: string, fingerprint: string, state: array<string, mixed>, saved_at: string}|null  $resumeCandidate
     */
    private function toRoute(array $result, ScenarioDefinition $definition, ?array $resumeCandidate): ?MenuRoute
    {
        $route = (string) ($result['route'] ?? 'none');

        if ($route === 'none') {
            return null;
        }

        $confidence = RouteConfidence::fromExtraction($result['confidence'] ?? null);

        if ($confidence === RouteConfidence::Low) {
            return null;
        }

        if ($route === 'service_question') {
            return MenuRoute::toServiceQuestion($confidence);
        }

        if ($route === 'resume') {
            return $resumeCandidate === null ? null : MenuRoute::toResume($confidence);
        }

        $owner = $definition->optionOwner(Str::after($route, 'option:'));

        if ($owner === null) {
            return null;
        }

        // The chosen option leads straight into the very node the paused
        // questionnaire is waiting at: routing it as an ordinary Option
        // would start a second, duplicate questionnaire next to the one
        // already in progress. Resuming the existing one is what the
        // contact actually wants — code decides this, not the model.
        if ($resumeCandidate !== null
            && $definition->target($owner['node_id'], ScenarioDefinition::optionOutput($owner['option_id'])) === $resumeCandidate['node_id']) {
            return MenuRoute::toResume($confidence);
        }

        return MenuRoute::toOption($owner, $confidence);
    }
}
