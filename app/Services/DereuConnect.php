<?php

namespace App\Services;

use App\Support\DereuOutboundGuard;
use RuntimeException;

/**
 * Hosted Embedded Signup: signs the payload for the connect.dereu.* page
 * and verifies the OUT redirect it sends the client back with.
 *
 * The scheme is a symmetric HMAC-SHA256 over a base64url string (not JWT):
 *   d   = base64url(json_encode(payload))
 *   sig = base64url(HMAC-SHA256(string d, connect signing secret))
 *   p   = key prefix of the partner credential, lets Dereu find the secret
 */
class DereuConnect
{
    public function __construct(
        protected string $signingSecret,
        protected string $keyPrefix,
        protected string $connectUrl,
    ) {}

    public function isConfigured(): bool
    {
        return $this->signingSecret !== '' && $this->keyPrefix !== '' && $this->connectUrl !== '';
    }

    /**
     * Derive the key prefix from a platform key of the form `plat_<prefix>.<secret>`.
     *
     * Dereu resolves the partner's connect secret by this prefix, so it is not
     * stored separately. Returns an empty string for a blank or malformed key,
     * which keeps isConfigured() honest.
     */
    public static function keyPrefixFromPlatformKey(?string $platformKey): string
    {
        if (blank($platformKey) || ! str_starts_with($platformKey, 'plat_')) {
            return '';
        }

        $rest = substr($platformKey, strlen('plat_'));

        if (! str_contains($rest, '.')) {
            return '';
        }

        return explode('.', $rest, 2)[0];
    }

    /**
     * Build the signed URL of the hosted connect page for a browser redirect.
     *
     * The nonce must be stored by the caller and consumed as one-time when
     * the OUT redirect comes back.
     */
    public function connectUrl(
        string $externalId,
        string $returnUrl,
        string $nonce,
        int $ttlSeconds = 600,
        ?string $companyName = null,
    ): string {
        $this->ensureConfigured();

        // The browser redirect is the one path to Dereu the HTTP client
        // never sees, so it asks the guard itself rather than relying on
        // whoever offers the button to have remembered.
        DereuOutboundGuard::ensureReachable($this->connectUrl);

        $payload = [
            'external_id' => $externalId,
            'return_url' => $returnUrl,
            'nonce' => $nonce,
            'exp' => now()->addSeconds($ttlSeconds)->getTimestamp(),
        ];

        if (filled($companyName)) {
            $payload['company_name'] = $companyName;
        }

        $data = static::base64UrlEncode(
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        return $this->connectUrl.'?'.http_build_query([
            'd' => $data,
            'p' => $this->keyPrefix,
            'sig' => static::sign($data, $this->signingSecret),
        ]);
    }

    /**
     * Verify and decode the OUT redirect query (?result=<b64url>&sig=<hmac>).
     *
     * Returns null when the signature or payload is invalid — treat as a
     * refusal. The caller must additionally consume the nonce as one-time.
     *
     * display_phone_number is optional on purpose: Dereu fetches it from Meta
     * softly, so it is absent both when Graph did not answer and when Dereu
     * itself is older than the field.
     *
     * @return array{dereu_company_id: string, phone_number_id: string, waba_id: string, status: string, nonce: string, transferred?: bool, display_phone_number?: string|null}|null
     */
    public function verifyResult(string $result, string $signature): ?array
    {
        $this->ensureConfigured();

        if (! hash_equals(static::sign($result, $this->signingSecret), $signature)) {
            return null;
        }

        $json = static::base64UrlDecode($result);

        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);

        if (! is_array($data)) {
            return null;
        }

        foreach (['dereu_company_id', 'phone_number_id', 'waba_id', 'status', 'nonce'] as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field])) {
                return null;
            }
        }

        /** @var array{dereu_company_id: string, phone_number_id: string, waba_id: string, status: string, nonce: string, transferred?: bool, display_phone_number?: string|null} $data */
        return $data;
    }

    public static function sign(string $message, string $secret): string
    {
        return static::base64UrlEncode(hash_hmac('sha256', $message, $secret, true));
    }

    public static function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): ?string
    {
        $base64 = strtr($value, '-_', '+/');
        $padding = strlen($base64) % 4;

        if ($padding > 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($base64, true);

        return $decoded === false ? null : $decoded;
    }

    protected function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Dereu Hosted Embedded Signup is not configured (DEREU_CONNECT_SECRET, DEREU_PLATFORM_KEY).',
            );
        }
    }
}
