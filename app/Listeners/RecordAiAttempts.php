<?php

namespace App\Listeners;

use App\Enums\AiAttemptStatus;
use App\Enums\AiCostStatus;
use App\Services\Ai\Audit\AiAuditState;
use App\Services\Ai\Audit\AiCostEstimator;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\GeneratingTranscription;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\TranscriptionGenerated;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Collects every real provider request into ai_attempts from the SDK
 * events: usage tokens, the actual provider/model, latency (measured
 * between the «prompting» and «prompted» events) and failover errors.
 * Rows attach to the AiOperation currently open in AiAudit::run().
 */
class RecordAiAttempts
{
    private const int TEXT_LIMIT = 8000;

    public function __construct(
        private readonly AiAuditState $state,
        private readonly AiCostEstimator $costs,
    ) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            PromptingAgent::class => 'onPromptingAgent',
            AgentPrompted::class => 'onAgentPrompted',
            AgentFailedOver::class => 'onAgentFailedOver',
            GeneratingTranscription::class => 'onGeneratingTranscription',
            TranscriptionGenerated::class => 'onTranscriptionGenerated',
        ];
    }

    public function onPromptingAgent(PromptingAgent $event): void
    {
        $this->state->begin(
            $event->invocationId,
            Str::limit($event->prompt->prompt, self::TEXT_LIMIT),
            $event->prompt->provider->name(),
            $event->prompt->model,
            [
                'agent' => class_basename($event->prompt->agent),
                'attachments' => $event->prompt->attachments->count(),
            ],
        );
    }

    public function onAgentPrompted(AgentPrompted $event): void
    {
        $pending = $this->state->finish($event->invocationId);

        $this->record(
            invocationId: $event->invocationId,
            provider: $event->response->meta->provider ?? $pending['provider'] ?? null,
            model: $event->response->meta->model ?? $pending['model'] ?? null,
            usage: $event->response->usage,
            pending: $pending,
            response: Str::limit($event->response->text, self::TEXT_LIMIT),
        );
    }

    /**
     * A failover step is a real (billed or errored) request in its own
     * right — journal it even though the operation may still succeed on
     * the next provider.
     */
    public function onAgentFailedOver(AgentFailedOver $event): void
    {
        $this->state->operation?->attempts()->create([
            'status' => AiAttemptStatus::Failed,
            'provider' => $event->provider->name(),
            'model' => $event->model,
            'error' => Str::limit($event->exception->getMessage(), 1000),
            'cost_status' => AiCostStatus::Unknown,
        ]);
    }

    public function onGeneratingTranscription(GeneratingTranscription $event): void
    {
        $this->state->begin(
            $event->invocationId,
            null,
            $event->provider->name(),
            $event->model,
            ['diarize' => $event->prompt->diarize, 'language' => $event->prompt->language],
        );
    }

    public function onTranscriptionGenerated(TranscriptionGenerated $event): void
    {
        $pending = $this->state->finish($event->invocationId);

        $this->record(
            invocationId: $event->invocationId,
            provider: $event->response->meta->provider ?? $event->provider->name(),
            model: $event->response->meta->model ?? $event->model,
            usage: $event->response->usage,
            pending: $pending,
            response: Str::limit($event->response->text, self::TEXT_LIMIT),
        );
    }

    /**
     * @param  array{started_at: float, prompt: string|null, provider: string|null, model: string|null, parameters: array<string, mixed>}|null  $pending
     */
    protected function record(string $invocationId, ?string $provider, ?string $model, Usage $usage, ?array $pending, ?string $response): void
    {
        $operation = $this->state->operation;

        if ($operation === null) {
            return; // вызов вне AiAudit::run() — журналировать некуда
        }

        $operation->attempts()->create([
            'status' => AiAttemptStatus::Succeeded,
            'provider' => $provider,
            'model' => $model,
            'invocation_id' => $invocationId,
            'input_tokens' => $usage->promptTokens,
            'output_tokens' => $usage->completionTokens,
            'cache_read_tokens' => $usage->cacheReadInputTokens,
            'cache_write_tokens' => $usage->cacheWriteInputTokens,
            'reasoning_tokens' => $usage->reasoningTokens,
            'latency_ms' => $pending !== null ? (int) ((microtime(true) - $pending['started_at']) * 1000) : null,
            'prompt' => $pending['prompt'] ?? null,
            'response' => $response,
            'parameters' => ($pending['parameters'] ?? []) ?: null,
            ...$this->costs->estimate(
                $model,
                $usage->promptTokens,
                $usage->completionTokens,
                $usage->cacheReadInputTokens,
                $usage->cacheWriteInputTokens,
            ),
        ]);
    }
}
