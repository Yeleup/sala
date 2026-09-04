<?php

namespace App\Support;

use App\Exceptions\OutboundRequestBlocked;
use Illuminate\Support\Facades\App;
use Psr\Http\Message\RequestInterface;

/**
 * The local safety catch of the WhatsApp channel. A developer machine runs
 * on a copy of production data — real suppliers, real phone numbers — and
 * a single stray call would message them for real or rewrite the live
 * template registry in Meta.
 *
 * The block sits on the HTTP client itself rather than on the three Dereu
 * clients: guarding callers one by one only covers the calls that exist
 * today, and the point of the catch is that code written tomorrow cannot
 * reach Dereu from a local environment either.
 *
 * Deliberately narrow — only the Dereu hosts. Everything else (OpenAI and
 * friends) keeps working, otherwise the bot stops being developable
 * locally, which is the reason the copy of production data is there at all.
 */
final class DereuOutboundGuard
{
    public function __invoke(RequestInterface $request): RequestInterface
    {
        self::ensureReachable((string) $request->getUri());

        return $request;
    }

    /**
     * Refuse a URL this environment must not reach. The one place that
     * decides, so the paths the HTTP client never sees — the hosted
     * connect redirect above all — refuse on the very same rule.
     */
    public static function ensureReachable(string $url): void
    {
        $host = self::hostOf($url);

        if (! self::blocksHost($host)) {
            return;
        }

        throw self::blockedHosts() === []
            ? OutboundRequestBlocked::channelUnrecognisable($host)
            : OutboundRequestBlocked::host($host);
    }

    /**
     * Whether a request to this URL would be refused — for the affordances
     * that should not be offered in the first place.
     */
    public static function blocksUrl(string $url): bool
    {
        return self::blocksHost(self::hostOf($url));
    }

    private static function blocksHost(string $host): bool
    {
        if (! self::blocksChannel() || $host === '') {
            return false;
        }

        // A mock served from this machine is the sanctioned way to exercise
        // the channel locally: that request never leaves the machine, which
        // is the whole of what this catch is here to prevent.
        if (self::staysOnThisMachine($host)) {
            return false;
        }

        $blockedHosts = self::blockedHosts();

        // No host to recognise means the guard cannot tell the channel from
        // the rest of the traffic — and on a copy of production data that
        // must not resolve to «let through».
        return $blockedHosts === [] || in_array($host, $blockedHosts, true);
    }

    private static function hostOf(string $url): string
    {
        return mb_strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    /**
     * Whether this environment is barred from reaching the WhatsApp
     * channel. Callers the HTTP client never sees — the hosted connect
     * redirect, the button that starts it — ask here, so the rule is not
     * spelled out in three places that can drift apart.
     */
    public static function blocksChannel(): bool
    {
        return App::environment('local');
    }

    /**
     * The hosts of the WhatsApp channel, taken from configuration so that
     * repointing Dereu moves the block with it. Lower-cased, because a
     * host is case-insensitive and PSR-7 normalizes the request's own.
     *
     * @return list<string>
     */
    public static function blockedHosts(): array
    {
        $hosts = [];

        foreach ([config('services.dereu.base_url'), config('services.dereu.connect.url')] as $url) {
            $host = is_string($url) && $url !== '' ? parse_url($url, PHP_URL_HOST) : null;

            if (is_string($host) && $host !== '') {
                $hosts[] = mb_strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Whether a request to this host would be served by this machine: the
     * loopback address, a development top-level domain, or a bare name
     * that only resolves inside the compose network.
     */
    private static function staysOnThisMachine(string $host): bool
    {
        return ! str_contains($host, '.')
            || str_starts_with($host, '127.')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.localhost');
    }
}
