<?php

namespace App\Services\Bot;

use App\Exceptions\SessionWindowClosed;
use App\Models\Contact;
use App\Models\ScenarioRun;
use App\Services\DereuMessenger;

/**
 * Routes button replies of scenario runs. Every button a run sends
 * carries an opaque flow:{token}:{option} payload, so the reply lands in
 * the exact run, published version and block that produced the button —
 * matching by the visible button text is never used. Such a reply can
 * arrive days later, whatever step of the main dialog the contact is on,
 * so the engine offers each inbound message here before the scenario.
 */
class ScenarioRunReplyHandler
{
    private const string PAYLOAD_PREFIX = 'flow:';

    public function __construct(
        private readonly ScenarioRunner $runner,
        private readonly DereuMessenger $messenger,
    ) {}

    public static function payload(ScenarioRun $run, string $optionId): string
    {
        return self::PAYLOAD_PREFIX.$run->token.':'.$optionId;
    }

    /**
     * True when the message was a run button reply and is fully handled —
     * the engine must not run the main dialog for it.
     */
    public function handle(Contact $contact, InboundMessage $message): bool
    {
        $replyId = (string) $message->replyId;

        if (! str_starts_with($replyId, self::PAYLOAD_PREFIX)) {
            return false;
        }

        $rest = substr($replyId, strlen(self::PAYLOAD_PREFIX));
        $separator = strpos($rest, ':');

        // We rendered every flow button ourselves, so a malformed or
        // unknown payload only means stale data — swallow it silently.
        if ($separator === false) {
            return true;
        }

        $run = ScenarioRun::query()->where('token', substr($rest, 0, $separator))->first();

        if ($run === null || $run->contact_id !== $contact->id) {
            return true;
        }

        if (! $run->isActive()) {
            $this->tellDecisionIsFinal($contact);

            return true;
        }

        $this->runner->handleReply($run, substr($rest, $separator + 1));

        return true;
    }

    /**
     * A click on a button of an already finished run — the decision it
     * asked about was consumed (or timed out) earlier.
     */
    protected function tellDecisionIsFinal(Contact $contact): void
    {
        try {
            $this->messenger->sendText($contact, 'Этот вопрос уже закрыт — ответ был зафиксирован ранее.');
        } catch (SessionWindowClosed) {
            // The reply itself normally opens the window; losing this
            // courtesy note is fine.
        }
    }
}
