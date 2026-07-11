<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * M2M client for the Dereu platform API (platform key auth).
 *
 * Company-scoped calls such as /messages/send use the per-company
 * dereu_ API key and do not belong here.
 */
class DereuPlatformClient
{
    /**
     * Re-issue the company api_key after a Hosted Embedded Signup — the OUT
     * redirect never exposes it. The previously issued key stops working.
     */
    public function reissueApiKey(string $externalId): string
    {
        $apiKey = $this->request()
            ->post("/platform/companies/{$externalId}/api-key/reissue")
            ->throw()
            ->json('api_key');

        if (blank($apiKey) || ! is_string($apiKey)) {
            throw new RuntimeException('Dereu did not return an api_key on re-issue.');
        }

        return $apiKey;
    }

    /**
     * Deactivate a Dereu company and stop inbound forwarding immediately.
     *
     * Without purge, Dereu keeps already received inbound messages for
     * another 30 days.
     *
     * @return array{dereu_company_id: string, deactivated: bool, purged: bool}
     */
    public function deprovisionCompany(string $externalId, bool $purge = false): array
    {
        return $this->request()
            ->delete("/platform/companies/{$externalId}".($purge ? '?purge=true' : ''))
            ->throw()
            ->json();
    }

    protected function request(): PendingRequest
    {
        $platformKey = config('services.dereu.platform_key');

        if (blank($platformKey)) {
            throw new RuntimeException('Dereu platform key is not configured (DEREU_PLATFORM_KEY).');
        }

        return Http::baseUrl(config('services.dereu.base_url'))
            ->withToken($platformKey)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->retry(2, 500, fn (mixed $exception): bool => $exception instanceof ConnectionException);
    }
}
