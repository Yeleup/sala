<?php

namespace App\Services\Ai\Audit;

use App\Enums\AiAttemptStatus;
use App\Enums\AiCostStatus;
use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Models\AiOperation;
use Closure;
use Illuminate\Support\Str;
use Throwable;

/**
 * Wraps a business AI call: creates the ai_operations row, lets the SDK
 * event subscriber attach every real provider request (ai_attempts) to
 * it, and records the plain failures the events alone cannot see — an
 * exception that bubbled out without a response.
 *
 *     $fields = $audit->run(AiOperationType::ListingExtraction,
 *         fn () => $agent->prompt(...),
 *         ['contact_id' => $contact->id, 'listing_id' => $draft->id]);
 */
class AiAudit
{
    public function __construct(private readonly AiAuditState $state) {}

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @param  array{contact_id?: int|null, bot_session_id?: int|null, listing_id?: int|null, channel_message_id?: int|null}  $links
     * @return TReturn
     */
    public function run(AiOperationType $operation, Closure $callback, array $links = [])
    {
        $record = AiOperation::create([
            'operation' => $operation,
            'status' => AiOperationStatus::Running,
            'channel_message_id' => $this->state->channelMessageId,
            ...$links,
        ]);

        $previous = $this->state->operation;
        $this->state->operation = $record;

        try {
            $result = $callback();

            $record->update(['status' => AiOperationStatus::Completed]);

            return $result;
        } catch (Throwable $e) {
            $this->flushPendingAsFailed($record, $e);
            $record->update([
                'status' => AiOperationStatus::Failed,
                'error' => Str::limit($e->getMessage(), 1000),
            ]);

            throw $e;
        } finally {
            $this->state->operation = $previous;
        }
    }

    /**
     * A hard failure fires no «prompted» event — close the invocations
     * that started inside this operation as failed attempts, so the retry
     * that threw is still visible in the journal.
     */
    protected function flushPendingAsFailed(AiOperation $record, Throwable $e): void
    {
        foreach ($this->state->pending as $invocationId => $pending) {
            $record->attempts()->create([
                'status' => AiAttemptStatus::Failed,
                'provider' => $pending['provider'],
                'model' => $pending['model'],
                'invocation_id' => $invocationId,
                'latency_ms' => (int) ((microtime(true) - $pending['started_at']) * 1000),
                'prompt' => $pending['prompt'],
                'error' => Str::limit($e->getMessage(), 1000),
                'parameters' => $pending['parameters'] ?: null,
                'cost_status' => AiCostStatus::Unknown,
            ]);
        }

        $this->state->pending = [];
    }
}
