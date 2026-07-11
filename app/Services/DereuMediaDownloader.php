<?php

namespace App\Services;

use App\Models\DereuCompany;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Downloads the bytes of an inbound WhatsApp media message from Dereu
 * (GET /media/{id}, company api_key auth) so the AI assistant can
 * transcribe voice messages and store photos.
 */
class DereuMediaDownloader
{
    /**
     * @return array{contents: string, mime_type: ?string}
     */
    public function download(string $mediaId): array
    {
        $company = DereuCompany::current();

        if ($company === null || ! $company->isConnected() || ! $company->hasApiKey()) {
            throw new RuntimeException('WhatsApp number is not connected — cannot download media.');
        }

        $response = $this->request($company->api_key)
            ->get("/media/{$mediaId}")
            ->throw();

        return [
            'contents' => $response->body(),
            'mime_type' => $response->header('Content-Type') ?: null,
        ];
    }

    protected function request(string $apiKey): PendingRequest
    {
        return Http::baseUrl(config('services.dereu.base_url'))
            ->withToken($apiKey)
            ->connectTimeout(5)
            ->timeout(30)
            ->retry(2, 500, fn (mixed $exception): bool => $exception instanceof ConnectionException);
    }
}
