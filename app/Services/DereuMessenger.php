<?php

namespace App\Services;

use App\Exceptions\SessionWindowClosed;
use App\Models\Contact;
use App\Models\DereuCompany;
use App\Models\WhatsappTemplate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Outbound WhatsApp messages through Dereu (company api_key auth).
 *
 * Payloads are Meta Cloud API message objects passed through by Dereu;
 * delivery statuses arrive asynchronously via the webhook. Free session
 * messages are deliverable only inside the contact's 24-hour window —
 * sending one outside it throws SessionWindowClosed; outside the window
 * use an approved Template Message (sendTemplate / sendTextOrTemplate).
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
     * A message with a single URL button (WhatsApp interactive cta_url) —
     * the handoff from the chat to the web interface.
     *
     * @param  string  $buttonText  Up to 20 characters (WhatsApp limit).
     */
    public function sendCtaUrl(Contact $contact, string $text, string $buttonText, string $url): void
    {
        $this->send($contact, 'interactive', [
            'type' => 'cta_url',
            'body' => ['text' => $text],
            'action' => [
                'name' => 'cta_url',
                'parameters' => ['display_text' => $buttonText, 'url' => $url],
            ],
        ]);
    }

    /**
     * @param  list<array{id: string, title: string, description?: string}>  $rows  Up to 10 (WhatsApp limit).
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
                        'rows' => array_map(fn (array $row): array => array_filter([
                            'id' => $row['id'],
                            'title' => $row['title'],
                            'description' => $row['description'] ?? null,
                        ], fn (mixed $value): bool => $value !== null), $rows),
                    ],
                ],
            ],
        ]);
    }

    /**
     * A paid Template Message — the only way to reach a contact outside
     * the 24-hour session window. The template must be approved by Meta.
     *
     * @param  list<string>  $bodyParameters  Values for the {{n}} placeholders of the template body, in order.
     * @param  list<string>  $buttonPayloads  Machine payloads for the template's quick-reply buttons, by button index;
     *                                        they come back in the reply so the bot knows what was answered.
     */
    public function sendTemplate(Contact $contact, WhatsappTemplate $template, array $bodyParameters = [], array $buttonPayloads = []): void
    {
        if (! $template->isApproved()) {
            throw new RuntimeException(sprintf(
                'Template "%s" (%s) is not approved by Meta — cannot send it.',
                $template->name,
                $template->language,
            ));
        }

        $payload = [
            'name' => $template->name,
            'language' => ['code' => $template->language],
        ];

        $components = [];

        if ($bodyParameters !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn (string $value): array => ['type' => 'text', 'text' => $value],
                    $bodyParameters,
                ),
            ];
        }

        foreach (array_values($buttonPayloads) as $index => $buttonPayload) {
            $components[] = [
                'type' => 'button',
                'sub_type' => 'quick_reply',
                'index' => (string) $index,
                'parameters' => [['type' => 'payload', 'payload' => $buttonPayload]],
            ];
        }

        if ($components !== []) {
            $payload['components'] = $components;
        }

        $this->send($contact, 'template', $payload);
    }

    /**
     * Session text while the 24-hour window is open, the approved template
     * otherwise — the channel choice WhatsApp imposes on every proactive
     * notification (customer requests, the 30-day relevance poll).
     *
     * @param  list<string>  $bodyParameters
     */
    public function sendTextOrTemplate(Contact $contact, string $text, WhatsappTemplate $template, array $bodyParameters = []): void
    {
        if ($contact->hasOpenSessionWindow()) {
            $this->sendText($contact, $text);

            return;
        }

        $this->sendTemplate($contact, $template, $bodyParameters);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function send(Contact $contact, string $type, array $payload): void
    {
        if ($type !== 'template' && ! $contact->hasOpenSessionWindow()) {
            throw new SessionWindowClosed($contact);
        }

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
