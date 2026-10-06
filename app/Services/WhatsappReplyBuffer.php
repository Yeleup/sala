<?php

namespace App\Services;

use App\Models\Contact;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One reply of the bot — everything it sends to the person it is
 * answering while handling their inbound message — delivered the way a
 * person reads it: a plain text followed right away by an interactive
 * message (reply buttons, a list, a URL button) goes out as ONE message,
 * the text opening its body. Since October 2026 every session message
 * beyond the month's free tier is paid, and the pair «greeting → menu»
 * alone was a hundred of them a month.
 *
 * While a reply is being collected, DereuMessenger holds back the latest
 * plain text to the addressee instead of sending it; the next send to the
 * addressee decides its fate: an interactive message it fits into absorbs
 * it, anything else sends it on its own first. Messages to anyone else
 * (the supplier notified about a request) are not part of the reply: they
 * go out at once and leave the held text alone.
 *
 * A held text must fail where it always failed — before the bot records
 * what came after it. So the code that records a step of the dialog or
 * acts on data (the engine saving the session, an assistant saving its
 * memory or the draft, a scenario run acting or closing) calls flush()
 * first: a failed send then stops the turn with the dialog where it stood,
 * and the queue's retry replays the same step. Where a pair must stay one
 * message, that code sends its own message first and records after.
 * flush() is never called from inside a database transaction — every
 * boundary sits before one is opened.
 *
 * Whatever is still held when the reply ends goes out then — also when
 * the reply dies with an exception, because before the text would already
 * have been delivered by that moment.
 *
 * Outside collect() nothing is held. Scoped, never a singleton: the held
 * text belongs to one queued job or one Octane request and must not leak
 * into the next one. A nested collect() (a job run synchronously inside
 * the reply) stays inside the same reply instead of resetting it.
 */
class WhatsappReplyBuffer
{
    private ?Contact $addressee = null;

    /** @var array{contact: Contact, text: string, send: Closure(): void}|null */
    private ?array $held = null;

    /**
     * Run one reply of the bot to the given person.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $reply
     * @return TResult
     */
    public function collect(Contact $addressee, Closure $reply): mixed
    {
        if ($this->addressee !== null) {
            return $reply();
        }

        $this->addressee = $addressee;

        try {
            $result = $reply();
        } catch (Throwable $e) {
            $this->flushAfter($e);

            throw $e;
        } finally {
            $this->addressee = null;
        }

        $this->flush();

        return $result;
    }

    /**
     * Something that is not an answer to the person — a notification by
     * an event, a scenario run launched for whoever it concerns — sends
     * straight away even in the middle of a reply. The text held before it
     * goes out first: it was written earlier, and its failure must not be
     * charged to the notification.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $sends
     * @return TResult
     */
    public function outside(Closure $sends): mixed
    {
        $this->flush();

        $addressee = $this->addressee;
        $this->addressee = null;

        try {
            return $sends();
        } finally {
            $this->addressee = $addressee;
        }
    }

    /**
     * Whether a plain text to this contact is held back now.
     */
    public function isCollectingFor(Contact $contact): bool
    {
        return $this->addressee !== null && $this->addressee->is($contact);
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
     * The held text leaves the buffer to travel inside the next message.
     *
     * @return array{contact: Contact, text: string, send: Closure(): void}|null
     */
    public function take(): ?array
    {
        $held = $this->held;
        $this->held = null;

        return $held;
    }

    /**
     * Dereu refused the message that was to carry the held text: the text
     * is still to be said — on its own, or inside whatever comes next.
     *
     * @param  array{contact: Contact, text: string, send: Closure(): void}  $held
     */
    public function putBack(array $held): void
    {
        $this->held = $held;
    }

    /**
     * Send the held text on its own, now.
     */
    public function flush(): void
    {
        $held = $this->take();

        if ($held !== null) {
            ($held['send'])();
        }
    }

    /**
     * Send the held text on its own before a message to the same person:
     * it was written first. Messages to anyone else do not touch it.
     */
    public function flushFor(Contact $contact): void
    {
        if ($this->heldTextFor($contact) !== null) {
            $this->flush();
        }
    }

    /**
     * Send the held text after something has already failed. Its own
     * failure is only logged: the exception that came first is the one the
     * caller has to see.
     */
    public function flushAfter(Throwable $failure): void
    {
        try {
            $this->flush();
        } catch (Throwable $e) {
            Log::warning('The held text of a failed bot reply could not be sent.', [
                'reply_error' => $failure->getMessage(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
