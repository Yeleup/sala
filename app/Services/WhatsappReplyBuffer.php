<?php

namespace App\Services;

use App\Models\Contact;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One reply of the bot — everything it sends while handling one inbound
 * message — delivered the way a person reads it: a plain text followed
 * right away by an interactive message (reply buttons, a list, a URL
 * button) to the same contact goes out as ONE message, the text opening
 * its body. Since October 2026 every session message beyond the month's
 * free tier is paid, and the pair «greeting → menu» alone was a hundred
 * of them a month.
 *
 * While a reply is being collected, DereuMessenger holds back the latest
 * plain text instead of sending it; the next send decides its fate:
 * an interactive message it fits into absorbs it, anything else sends it
 * on its own first. Whatever is still held when the reply ends goes out
 * then — also when the reply dies with an exception, because before the
 * text would already have been delivered by that moment.
 *
 * Outside collect() nothing is held: notifiers, controllers and every
 * other send keep going out immediately. Scoped, never a singleton: the
 * held text belongs to one queued job or one Octane request and must not
 * leak into the next one. A nested collect() (a job run synchronously
 * inside the reply) stays inside the same reply instead of resetting it.
 *
 * A caller that swallows its own send failures, or charges them to
 * something of its own (a scenario run, a request), flushes first: a held
 * text sent as part of its send would otherwise fail on its account.
 */
class WhatsappReplyBuffer
{
    private bool $collecting = false;

    /** @var array{contact: Contact, text: string, send: Closure(): void}|null */
    private ?array $held = null;

    /**
     * Run one reply of the bot.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $reply
     * @return TResult
     */
    public function collect(Closure $reply): mixed
    {
        if ($this->collecting) {
            return $reply();
        }

        $this->collecting = true;

        try {
            $result = $reply();
        } catch (Throwable $e) {
            $this->flushAfterFailure($e);

            throw $e;
        } finally {
            $this->collecting = false;
        }

        $this->flush();

        return $result;
    }

    public function isCollecting(): bool
    {
        return $this->collecting;
    }

    /**
     * Hold a plain text until the next send. The caller has already
     * flushed whatever was held before: of two texts in a row, the first
     * one goes out on its own, as it always did.
     *
     * @param  Closure(): void  $send  Sends the text on its own.
     */
    public function hold(Contact $contact, string $text, Closure $send): void
    {
        $this->held = ['contact' => $contact, 'text' => $text, 'send' => $send];
    }

    /**
     * The held text, when it is addressed to this contact.
     */
    public function heldTextFor(Contact $contact): ?string
    {
        return $this->held !== null && $this->held['contact']->is($contact)
            ? $this->held['text']
            : null;
    }

    /**
     * The held text went out inside the next message — nothing is left
     * to send on its own.
     */
    public function forget(): void
    {
        $this->held = null;
    }

    /**
     * Send the held text on its own, now.
     */
    public function flush(): void
    {
        $held = $this->held;

        if ($held === null) {
            return;
        }

        $this->held = null;

        ($held['send'])();
    }

    /**
     * The reply died, but its held text was written before that and must
     * still go out. Its own failure is only logged: the exception that
     * killed the reply is the one the caller has to see.
     */
    private function flushAfterFailure(Throwable $replyFailure): void
    {
        try {
            $this->flush();
        } catch (Throwable $e) {
            Log::warning('The held text of a failed bot reply could not be sent.', [
                'reply_error' => $replyFailure->getMessage(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
