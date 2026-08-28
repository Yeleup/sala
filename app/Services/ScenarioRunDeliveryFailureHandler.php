<?php

namespace App\Services;

use App\Enums\ScenarioRunStatus;
use App\Models\ChannelMessage;
use App\Models\ScenarioRun;
use App\Services\Bot\ScenarioRunReplyHandler;
use Illuminate\Support\Facades\Log;

/**
 * Marks a run whose question Meta rejected asynchronously — after Dereu
 * had already accepted the send — as undelivered instead of leaving it
 * «Ждёт ответа». Nobody was asked anything, so the journal must not show
 * the silence as waiting: the operator reading the list has no other way
 * to tell a supplier who is thinking it over from one who never got the
 * message.
 *
 * The failed message is tied back to its run by the flow:{token} button
 * payloads it carried — the same ids the reply would have carried.
 */
class ScenarioRunDeliveryFailureHandler
{
    public function handle(ChannelMessage $failed): void
    {
        $tokens = $failed->buttonIds()
            ->map(ScenarioRunReplyHandler::runToken(...))
            ->filter()
            ->unique();

        if ($tokens->isEmpty()) {
            return;
        }

        ScenarioRun::query()
            ->whereIn('token', $tokens->all())
            ->where('status', ScenarioRunStatus::Active)
            ->get()
            ->filter(fn (ScenarioRun $run): bool => $this->isWaitingForThisMessage($run, $failed))
            ->each(function (ScenarioRun $run) use ($failed): void {
                Log::warning('The scenario run question never reached the contact — the run is marked undelivered.', [
                    'scenario_run_id' => $run->id,
                    'channel_message_id' => $failed->id,
                    'reason' => $failed->failure_reason,
                ]);

                // current_node_id остаётся: по нему видно, какой именно
                // вопрос не дошёл — так же, как у синхронного отказа.
                $run->forceFill([
                    'status' => ScenarioRunStatus::Failed,
                    'timeout_at' => null,
                ])->save();
            });
    }

    /**
     * Whether the run is standing on the very block whose message failed.
     * A run that already moved on has been answered — a redelivered (or
     * simply late) failure of an earlier block says nothing about the
     * question it is waiting on now, and must not bury a live run.
     */
    protected function isWaitingForThisMessage(ScenarioRun $run, ChannelMessage $failed): bool
    {
        $definition = $run->scenarioDefinition();
        $node = $definition?->node($run->current_node_id);

        if ($definition === null || $node === null) {
            return false;
        }

        $awaited = collect($definition->options($node))
            ->map(fn (array $option): string => ScenarioRunReplyHandler::payload($run, (string) $option['id']));

        return $failed->buttonIds()->intersect($awaited)->isNotEmpty();
    }
}
