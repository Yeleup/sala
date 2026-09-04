<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A local environment tried to reach Dereu and was stopped before the
 * request left the machine. Local runs on a copy of production data, so
 * the call would have addressed real suppliers or the live template
 * registry in Meta — see App\Support\DereuOutboundGuard for why the block
 * sits on the HTTP client rather than on each caller.
 *
 * To exercise the channel locally, point DEREU_BASE_URL at a mock running
 * on this machine: a request that never leaves the machine is not blocked.
 */
class OutboundRequestBlocked extends RuntimeException
{
    private function __construct(string $message, public readonly string $host)
    {
        parent::__construct($message);
    }

    public static function host(string $host): self
    {
        return new self(sprintf(
            'Blocked an outbound request to %s: the local environment must not reach the WhatsApp channel.',
            $host,
        ), $host);
    }

    /**
     * Configuration named no host at all, so the guard cannot tell the
     * WhatsApp channel from the rest of the traffic. On a copy of
     * production data «cannot tell» must not resolve to «let through».
     */
    public static function channelUnrecognisable(string $host): self
    {
        return new self(sprintf(
            'Blocked an outbound request to %s: neither DEREU_BASE_URL nor DEREU_CONNECT_URL names a host, '
            .'so the local guard cannot tell the WhatsApp channel apart and refuses to guess.',
            $host,
        ), $host);
    }
}
