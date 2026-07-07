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
     * Create or fetch a Dereu company. Idempotent by external id.
     *
     * The api_key is present in the response only on first creation (201);
     * a repeated call returns already_provisioned without the key.
     *
     * @return array{dereu_company_id: string, api_key?: string, already_provisioned?: bool, phone_number_id_registered?: bool}
     */
    public function provisionCompany(string $externalId, ?string $name = null): array
    {
        return $this->request()
            ->post('/platform/companies', array_filter([
                'external_id' => $externalId,
                'name' => $name,
            ]))
            ->throw()
            ->json();
    }

    /**
     * Deactivate a Dereu company and stop inbound forwarding immediately.
     *
     * @return array{dereu_company_id: string, deactivated: bool, purged: bool}
     */
    public function deprovisionCompany(string $externalId, bool $purge = false): array
    {
        return $this->request()
            ->delete("/platform/companies/{$externalId}", $purge ? ['purge' => 'true'] : [])
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
