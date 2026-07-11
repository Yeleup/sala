<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\DereuCompany;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Outbound WhatsApp messages through Dereu (company api_key auth).
 *
 * Payloads are Meta Cloud API message objects passed through by Dereu;
 * delivery statuses arrive asynchronously via the webhook. Free session
 * messages reach the contact only inside the 24-hour window
 * (Contact::hasOpenSessionWindow()).
 */
class DereuMessenger
{
    public function sendText(Contact $contact, string $text): void
    {
        $this->send($contact, 'text', ['body' => $text]);
    }

    /**
     * @param  list<array{id: string, title: string}>  $buttons  Up to 3 (WhatsApp limit).
     */
    public function sendButtons(Contact $contact, string $text, array $buttons): void
    {
        $this->send($contact, 'interactive', [
            'type' => 'button',
            'body' => ['text' => $text],
            'action' => [
                'buttons' => array_map(fn (array $button): array => [
                    'type' => 'reply',
                    'reply' => ['id' => $button['id'], 'title' => $button['title']],
                ], $buttons),
            ],
        ]);
    }

    /**
     * @param  list<array{id: string, title: string}>  $rows  Up to 10 (WhatsApp limit).
     */
    public function sendList(Contact $contact, string $text, string $button, array $rows): void
    {
        $this->send($contact, 'interactive', [
            'type' => 'list',
            'body' => ['text' => $text],
            'action' => [
                'button' => $button,
                'sections' => [
                    [
                        'rows' => array_map(fn (array $row): array => [
                            'id' => $row['id'],
                            'title' => $row['title'],
                        ], $rows),
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function send(Contact $contact, string $type, array $payload): void
    {
        $company = DereuCompany::current();

        if ($company === null || ! $company->isConnected() || ! $company->hasApiKey()) {
            throw new RuntimeException('WhatsApp number is not connected — cannot send messages.');
        }

        $this->request($company->api_key)
            ->post('/messages/send', [
                'phone_number_id' => $company->phone_number_id,
                'to' => '+'.ltrim($contact->phone, '+'),
                'type' => $type,
                'payload' => $payload,
            ])
            ->throw();
    }

    protected function request(string $apiKey): PendingRequest
    {
        return Http::baseUrl(config('services.dereu.base_url'))
            ->withToken($apiKey)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->retry(2, 500, fn (mixed $exception): bool => $exception instanceof ConnectionException);
    }
}
