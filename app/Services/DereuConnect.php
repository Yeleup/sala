<?php

namespace App\Services;

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
        ?string $accountMode = null,
    ): string {
        $this->ensureConfigured();

        $payload = [
            'external_id' => $externalId,
            'return_url' => $returnUrl,
            'nonce' => $nonce,
            'exp' => now()->addSeconds($ttlSeconds)->getTimestamp(),
        ];

        if (filled($companyName)) {
            $payload['company_name'] = $companyName;
        }

        if (filled($accountMode)) {
            $payload['account_mode'] = $accountMode;
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
     * @return array{dereu_company_id: string, phone_number_id: string, waba_id: string, status: string, nonce: string}|null
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

        /** @var array{dereu_company_id: string, phone_number_id: string, waba_id: string, status: string, nonce: string} $data */
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
                'Dereu Hosted Embedded Signup is not configured (DEREU_CONNECT_SECRET, DEREU_CONNECT_PREFIX).',
            );
        }
    }
}
