<?php

namespace App\Services;

use App\Enums\ChannelDirection;
use App\Enums\ChannelMessageStatus;
use App\Exceptions\SessionWindowClosed;
use App\Models\ChannelMessage;
use App\Models\Contact;
use App\Models\DereuCompany;
use App\Models\WhatsappTemplate;
use App\Support\WhatsappText;
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
    /** WhatsApp Cloud API interactive field limits — sending anything longer fails the whole request. */
    private const int BUTTON_TITLE_LIMIT = 20;

    private const int BUTTON_ID_LIMIT = 256;

    private const int BODY_LIMIT = 1024;

    private const int LIST_BODY_LIMIT = 4096;

    private const int LIST_ROW_ID_LIMIT = 200;

    private const int LIST_ROW_TITLE_LIMIT = 24;

    private const int LIST_ROW_DESCRIPTION_LIMIT = 72;

    public function __construct(private readonly WhatsappCostEstimator $costs) {}

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
            'body' => ['text' => WhatsappText::clamp($text, self::BODY_LIMIT)],
            'action' => [
                'buttons' => array_map(fn (array $button): array => [
                    'type' => 'reply',
                    'reply' => [
                        'id' => WhatsappText::clamp($button['id'], self::BUTTON_ID_LIMIT),
                        'title' => WhatsappText::clamp($button['title'], self::BUTTON_TITLE_LIMIT),
                    ],
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
            'body' => ['text' => WhatsappText::clamp($text, self::BODY_LIMIT)],
            'action' => [
                'name' => 'cta_url',
                'parameters' => [
                    'display_text' => WhatsappText::clamp($buttonText, self::BUTTON_TITLE_LIMIT),
                    'url' => $url,
                ],
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
            'body' => ['text' => WhatsappText::clamp($text, self::LIST_BODY_LIMIT)],
            'action' => [
                'button' => WhatsappText::clamp($button, self::BUTTON_TITLE_LIMIT),
                'sections' => [
                    [
                        'rows' => array_map(fn (array $row): array => array_filter([
                            'id' => WhatsappText::clamp($row['id'], self::LIST_ROW_ID_LIMIT),
                            'title' => WhatsappText::clamp($row['title'], self::LIST_ROW_TITLE_LIMIT),
                            'description' => isset($row['description'])
                                ? WhatsappText::clamp($row['description'], self::LIST_ROW_DESCRIPTION_LIMIT)
                                : null,
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

        $this->send($contact, 'template', $payload, $template);
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
    protected function send(Contact $contact, string $type, array $payload, ?WhatsappTemplate $template = null): void
    {
        if ($type !== 'template' && ! $contact->hasOpenSessionWindow()) {
            throw new SessionWindowClosed($contact);
        }

        $company = DereuCompany::current();

        if ($company === null || ! $company->isConnected() || ! $company->hasApiKey()) {
            throw new RuntimeException('WhatsApp number is not connected — cannot send messages.');
        }

        $response = $this->request($company->api_key)
            ->post('/messages/send', [
                'phone_number_id' => $company->phone_number_id,
                'to' => '+'.ltrim($contact->phone, '+'),
                'type' => $type,
                'payload' => $payload,
            ])
            ->throw();

        ChannelMessage::create([
            'contact_id' => $contact->id,
            'direction' => ChannelDirection::Outbound,
            'type' => $type,
            'text' => $this->outboundText($type, $payload),
            'payload' => $payload,
            'dereu_message_id' => $response->json('id'),
            'status' => ChannelMessageStatus::Queued,
            ...($template === null ? [] : [
                'whatsapp_template_id' => $template->id,
                ...$this->costs->estimate($template->category),
            ]),
        ]);
    }

    /**
     * A short human-readable body for the journal list; the full payload
     * is stored next to it anyway.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function outboundText(string $type, array $payload): ?string
    {
        return match ($type) {
            'text' => $payload['body'] ?? null,
            'interactive' => $payload['body']['text'] ?? null,
            'template' => 'Шаблон: '.($payload['name'] ?? '?'),
            default => null,
        };
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
