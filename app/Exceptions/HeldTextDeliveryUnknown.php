<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The message that carried the bot reply's held text — a line the bot had
 * to say, joined into the interactive message after it (see
 * App\Services\WhatsappReplyBuffer) — got no answer from Dereu: the
 * connection broke or timed out, so nobody knows whether the person got
 * it. The text is not sent again: it may already be on their screen.
 *
 * Nor is this the failure of the interactive message alone. A caller that
 * treats its own message as optional and swallows its failures (a
 * best-effort catalog button) must let this through: the turn then stops
 * before anything is recorded, and the queue retries it from the same
 * place, as it did when the text went out on its own.
 */
class HeldTextDeliveryUnknown extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct(
            'No answer from Dereu on a message carrying the held text of a bot reply: '.$previous->getMessage(),
            previous: $previous,
        );
    }
}
