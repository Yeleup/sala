<?php

namespace App\Services\Bot;

use App\Enums\BotNodeType;
use App\Enums\ScenarioAction;
use App\Enums\ScenarioActionOutcome;
use App\Enums\ScenarioCondition;
use App\Enums\ScenarioMessageChannel;
use App\Enums\ScenarioRunStatus;
use App\Models\BotScenario;
use App\Models\Contact;
use App\Models\ScenarioRun;
use App\Models\WhatsappTemplate;
use App\Services\Ai\CtaLinkBuilder;
use App\Services\DereuMessenger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Drives isolated runs of run-based scenarios (auto-scenarios and
 * broadcasts). One launch — one ScenarioRun pinned to the currently
 * published version; the run advances through the graph until a
 * «WhatsApp-сообщение» block with buttons stops it, then continues on
 * the button reply routed here by its flow:{token}:{option} payload, or
 * on the reply timeout.
 *
 * Runs never touch the contact's main dialog session, so a supplier can
 * simultaneously await answers about several requests and listings.
 */
class ScenarioRunner
{
    /** Safety cap on auto-advanced blocks per step, mirrors BotEngine. */
    private const int MAX_STEPS = 20;

    public function __construct(
        private readonly DereuMessenger $messenger,
        private readonly ScenarioVariableResolver $variables,
        private readonly ScenarioConditionEvaluator $conditions,
        private readonly ScenarioActionExecutor $actions,
        private readonly CtaLinkBuilder $links,
    ) {}

    /**
     * Null when the scenario is unpublished or the first delivery failed
     * (closed window without an approved template, transport error) — the
     * failed run stays in the journal, the caller may retry later.
     */
    public function launch(BotScenario $scenario, Contact $contact, ?Model $subject = null): ?ScenarioRun
    {
        $definition = $scenario->publishedDefinition();

        if ($definition === null) {
            return null;
        }

        $run = new ScenarioRun([
            'token' => ScenarioRun::generateToken(),
            'bot_scenario_id' => $scenario->id,
            'scenario_version' => $scenario->published_version,
            'contact_id' => $contact->id,
            'status' => ScenarioRunStatus::Active,
        ]);

        if ($subject !== null) {
            $run->subject()->associate($subject);
        }

        $run->save();
        $run->setRelation('contact', $contact);

        try {
            $this->advance($run, $definition, $definition->startNodeId());
        } catch (Throwable $e) {
            $this->fail($run, $e);

            return null;
        }

        return $run;
    }

    /**
     * A button reply of a waiting run. An option the current block does
     * not have (e.g. a button of an earlier block of the same run) is
     * ignored — every consumed decision already advanced the graph.
     */
    public function handleReply(ScenarioRun $run, string $optionId): void
    {
        $definition = $run->scenarioDefinition();
        $node = $definition?->node($run->current_node_id);

        if ($definition === null || $node === null) {
            $this->complete($run);

            return;
        }

        $matches = collect($definition->options($node))
            ->contains(fn (array $option): bool => ($option['id'] ?? null) === $optionId);

        if (! $matches) {
            return;
        }

        try {
            $this->advance($run, $definition, $definition->target($node['id'], ScenarioDefinition::optionOutput($optionId)));
        } catch (Throwable $e) {
            $this->fail($run, $e);
        }
    }

    /**
     * The reply timeout of a waiting run: follow the block's «timeout»
     * output, or quietly finish the run when it is not wired.
     */
    public function handleTimeout(ScenarioRun $run): void
    {
        $definition = $run->scenarioDefinition();
        $node = $definition?->node($run->current_node_id);

        if ($definition === null || $node === null) {
            $this->complete($run);

            return;
        }

        $target = $definition->target($node['id'], ScenarioDefinition::OUTPUT_TIMEOUT);

        if ($target === null) {
            $this->complete($run);

            return;
        }

        try {
            $this->advance($run, $definition, $target);
        } catch (Throwable $e) {
            $this->fail($run, $e);
        }
    }

    private function advance(ScenarioRun $run, ScenarioDefinition $definition, ?string $nodeId): void
    {
        for ($steps = 0; $steps < self::MAX_STEPS; $steps++) {
            $node = $definition->node($nodeId);
            $type = $definition->nodeType($node);

            if ($node === null || $type === null) {
                $this->complete($run);

                return;
            }

            switch ($type) {
                case BotNodeType::Start:
                    $nodeId = $definition->target($node['id'], ScenarioDefinition::OUTPUT_CONTINUE);
                    break;

                case BotNodeType::Text:
                    $text = $this->variables->substitute($run, (string) ($node['text'] ?? ''));

                    if (filled($text)) {
                        $this->messenger->sendText($run->contact, $text);
                    }

                    $nodeId = $definition->target($node['id'], ScenarioDefinition::OUTPUT_CONTINUE);
                    break;

                case BotNodeType::MyListings:
                    $this->messenger->sendCtaUrl(
                        $run->contact,
                        (string) ($node['text'] ?? '') ?: 'Откройте кабинет — там ваши объявления, статусы и причины отклонения.',
                        'Открыть кабинет',
                        $this->links->myListingsUrl($run->contact),
                    );

                    $nodeId = $definition->target($node['id'], ScenarioDefinition::OUTPUT_CONTINUE);
                    break;

                case BotNodeType::Message:
                    $this->sendMessage($run, $definition, $node);

                    if ($definition->nodeWaitsForInput($node)) {
                        $this->waitAt($run, $node, $definition);

                        return;
                    }

                    $nodeId = $definition->target($node['id'], ScenarioDefinition::OUTPUT_CONTINUE);
                    break;

                case BotNodeType::Condition:
                    $condition = ScenarioCondition::tryFrom((string) ($node['condition'] ?? ''));

                    if ($condition === null) {
                        $this->complete($run);

                        return;
                    }

                    $nodeId = $definition->target(
                        $node['id'],
                        $this->conditions->evaluate($run, $condition) ? ScenarioDefinition::OUTPUT_YES : ScenarioDefinition::OUTPUT_NO,
                    );
                    break;

                case BotNodeType::Action:
                    $action = ScenarioAction::tryFrom((string) ($node['action'] ?? ''));

                    $outcome = $action === null
                        ? ScenarioActionOutcome::Done
                        : $this->actions->execute($run, $action, $node);

                    // Неподключённый выход «skipped» тихо завершает запуск
                    // (target() → null), как и любой другой выход.
                    $nodeId = $definition->target(
                        $node['id'],
                        $outcome === ScenarioActionOutcome::Skipped ? ScenarioDefinition::OUTPUT_SKIPPED : ScenarioDefinition::OUTPUT_CONTINUE,
                    );
                    break;

                case BotNodeType::End:
                default:
                    // Main-dialog-only blocks cannot be published into a
                    // run-based scenario — validation forbids them.
                    $this->complete($run);

                    return;
            }
        }

        // Step cap reached — a cycle of auto-advancing blocks.
        $this->complete($run);
    }

    /**
     * Delivers a «WhatsApp-сообщение» block. For the template-sourced
     * channels the template body is the single source of the wording: the
     * paid template goes out outside the 24-hour window, and inside it the
     * same body (with the {{n}} values substituted) is delivered as a free
     * session message — the recipient sees one text either way. Only the
     * «только сессионное» channel keeps its own editable text. Every
     * button carries the run's opaque token, so the reply is routed by
     * the token — never by the button text.
     *
     * @param  array<string, mixed>  $node
     */
    private function sendMessage(ScenarioRun $run, ScenarioDefinition $definition, array $node): void
    {
        $channel = ScenarioMessageChannel::fromNode($node['channel'] ?? null);
        $options = $definition->options($node);

        if ($channel === ScenarioMessageChannel::SessionOnly) {
            $this->sendSession($run, $this->variables->substitute($run, (string) ($node['text'] ?? '')), $options);

            return;
        }

        $templateName = trim((string) ($node['template_name'] ?? ''));
        $template = $templateName === '' ? null : WhatsappTemplate::query()
            ->where('name', $templateName)
            ->first();

        if ($template === null) {
            throw new RuntimeException(sprintf(
                'Template "%s" is not in the registry — the message block has no text source.',
                $templateName === '' ? '?' : $templateName,
            ));
        }

        $variableKeys = array_values($node['variables'] ?? []);

        // A pending template already carries the wording, and a session
        // message needs no Meta approval — inside the window the block
        // keeps working while the moderation verdict is on its way.
        if ($channel === ScenarioMessageChannel::Adaptive && $run->contact->hasOpenSessionWindow()) {
            $this->sendSession($run, $this->variables->renderTemplateBody($run, (string) $template->body, $variableKeys), $options);

            return;
        }

        $this->messenger->sendTemplate(
            $run->contact,
            $template,
            $this->variables->values($run, $variableKeys),
            array_map(fn (array $option): string => ScenarioRunReplyHandler::payload($run, (string) $option['id']), $options),
        );
    }

    /**
     * @param  list<array{id: string, title: string}>  $options
     */
    private function sendSession(ScenarioRun $run, string $text, array $options): void
    {
        if ($options === []) {
            $this->messenger->sendText($run->contact, $text);

            return;
        }

        $this->messenger->sendButtons($run->contact, $text, array_map(fn (array $option): array => [
            'id' => ScenarioRunReplyHandler::payload($run, (string) $option['id']),
            'title' => (string) $option['title'],
        ], $options));
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function waitAt(ScenarioRun $run, array $node, ScenarioDefinition $definition): void
    {
        $timeoutHours = $definition->timeoutHours($node);

        $run->forceFill([
            'current_node_id' => $node['id'],
            'timeout_at' => $timeoutHours === null ? null : now()->addHours($timeoutHours),
        ])->save();
    }

    private function complete(ScenarioRun $run): void
    {
        $run->forceFill([
            'status' => ScenarioRunStatus::Completed,
            'current_node_id' => null,
            'timeout_at' => null,
        ])->save();
    }

    private function fail(ScenarioRun $run, Throwable $e): void
    {
        Log::warning('Scenario run failed.', [
            'scenario_run_id' => $run->id,
            'bot_scenario_id' => $run->bot_scenario_id,
            'error' => $e->getMessage(),
        ]);

        $run->forceFill([
            'status' => ScenarioRunStatus::Failed,
            'timeout_at' => null,
        ])->save();
    }
}
