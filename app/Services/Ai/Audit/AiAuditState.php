<?php

namespace App\Services\Ai\Audit;

use App\Models\AiOperation;

/**
 * Per-request working state of the AI audit: the operation currently
 * running under AiAudit::run(), invocations started but not yet finished,
 * and the inbound channel message being processed (for linkage).
 *
 * Bound as a *scoped* singleton: Laravel resets it between queued jobs and
 * Octane requests, so state never leaks across unrelated dialogs.
 */
class AiAuditState
{
    public ?AiOperation $operation = null;

    public ?int $channelMessageId = null;

    /**
     * Invocations that fired PromptingAgent/GeneratingTranscription and
     * have not produced a response yet.
     *
     * @var array<string, array{started_at: float, prompt: string|null, provider: string|null, model: string|null, parameters: array<string, mixed>}>
     */
    public array $pending = [];

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function begin(string $invocationId, ?string $prompt, ?string $provider, ?string $model, array $parameters = []): void
    {
        $this->pending[$invocationId] = [
            'started_at' => microtime(true),
            'prompt' => $prompt,
            'provider' => $provider,
            'model' => $model,
            'parameters' => $parameters,
        ];
    }

    /**
     * @return array{started_at: float, prompt: string|null, provider: string|null, model: string|null, parameters: array<string, mixed>}|null
     */
    public function finish(string $invocationId): ?array
    {
        $pending = $this->pending[$invocationId] ?? null;
        unset($this->pending[$invocationId]);

        return $pending;
    }
}
