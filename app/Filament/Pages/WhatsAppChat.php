<?php

namespace App\Filament\Pages;

use App\Enums\AiCostStatus;
use App\Enums\ChannelDirection;
use App\Enums\ChannelMessageStatus;
use App\Filament\Clusters\WhatsApp\WhatsAppCluster;
use App\Models\AiAttempt;
use App\Models\ChannelMessage;
use App\Models\Contact;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Read-only operator view over the WhatsApp channel journal: dialogs by
 * last activity on the left, the message thread on the right. Inbound
 * messages expose the AI operations they triggered (prompt, response,
 * tokens, money); the thread header shows what the contact has cost so
 * far. Money follows the delivered-based rule: template spend counts only
 * delivered/read messages (Meta bills per delivered template message).
 */
class WhatsAppChat extends Page
{
    private const PAGE_SIZE = 50;

    /** Statuses Meta actually bills a template message for. */
    private const BILLABLE_STATUSES = [ChannelMessageStatus::Delivered, ChannelMessageStatus::Read];

    protected static ?string $slug = 'chat';

    protected string $view = 'filament.pages.whats-app-chat';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftEllipsis;

    protected static ?string $cluster = WhatsAppCluster::class;

    protected static ?string $navigationLabel = 'Чат';

    protected static ?string $title = 'Чат WhatsApp';

    protected static ?int $navigationSort = 1;

    #[Url]
    public ?int $contactId = null;

    public string $search = '';

    public int $visible = self::PAGE_SIZE;

    public function selectContact(int $contactId): void
    {
        $this->contactId = $contactId;
        $this->visible = self::PAGE_SIZE;
    }

    public function loadOlder(): void
    {
        $this->visible += self::PAGE_SIZE;
    }

    /**
     * Dialogs ordered by last message, newest first.
     *
     * @return Collection<int, Contact>
     */
    public function dialogs(): Collection
    {
        $lastMessage = fn (string $column) => ChannelMessage::query()
            ->select($column)
            ->whereColumn('contact_id', 'contacts.id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(1);

        return Contact::query()
            ->whereHas('channelMessages')
            ->when($this->search !== '', function ($query): void {
                $term = '%'.trim($this->search).'%';
                $query->where(fn ($q) => $q
                    ->where('phone', 'ilike', $term)
                    ->orWhere('display_name', 'ilike', $term)
                    ->orWhere('profile_name', 'ilike', $term));
            })
            ->select('contacts.*')
            ->selectSub($lastMessage('created_at'), 'last_message_at')
            ->selectSub($lastMessage('text'), 'last_message_text')
            ->orderByDesc('last_message_at')
            ->limit(self::PAGE_SIZE)
            ->get();
    }

    public function selectedContact(): ?Contact
    {
        return $this->contactId !== null ? Contact::find($this->contactId) : null;
    }

    /**
     * The last $visible messages of the selected dialog, oldest first,
     * with a flag telling whether older ones exist beyond the window.
     *
     * @return array{messages: Collection<int, ChannelMessage>, has_older: bool}
     */
    public function thread(): array
    {
        if ($this->contactId === null) {
            return ['messages' => new Collection, 'has_older' => false];
        }

        $messages = ChannelMessage::query()
            ->where('contact_id', $this->contactId)
            ->with(['aiOperations.attempts', 'template'])
            ->orderByDesc('id')
            ->limit($this->visible + 1)
            ->get();

        $hasOlder = $messages->count() > $this->visible;

        return [
            'messages' => $messages->take($this->visible)->reverse()->values(),
            'has_older' => $hasOlder,
        ];
    }

    /**
     * What the message payload carried beyond plain text: interactive
     * reply buttons, list rows behind the opener button, CTA links, the
     * template body with substituted values, the machine id of the option
     * the contact picked, and Meta errors of broken inbound payloads. The
     * chat is an observation window — the operator must see exactly what
     * the bot offered and what was tapped, not a bare «Интерактив» chip.
     *
     * @return array{chip: ?string, buttons: list<array{title: ?string, id: ?string, url: ?string}>, list_button: ?string, rows: list<array{id: ?string, title: ?string, description: ?string}>, body: ?string, reply_id: ?string, errors: list<string>}
     */
    public function messageExtras(ChannelMessage $message): array
    {
        $payload = $message->payload ?? [];

        $extras = [
            'chip' => null,
            'buttons' => [],
            'list_button' => null,
            'rows' => [],
            'body' => null,
            'reply_id' => null,
            'errors' => $this->payloadErrors($payload),
        ];

        return match (true) {
            $message->type === 'interactive' && $message->direction === ChannelDirection::Outbound => $this->outboundInteractiveExtras($payload, $extras),
            $message->type === 'interactive' => $this->inboundInteractiveExtras($payload, $extras),
            $message->type === 'button' => [...$extras, 'chip' => '↩ Кнопка шаблона', 'reply_id' => $this->stringOrNull($payload['payload'] ?? null)],
            $message->type === 'template' => $this->templateExtras($message, $payload, $extras),
            default => $extras,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{chip: ?string, buttons: list<array{title: ?string, id: ?string, url: ?string}>, list_button: ?string, rows: list<array{id: ?string, title: ?string, description: ?string}>, body: ?string, reply_id: ?string, errors: list<string>}  $extras
     * @return array{chip: ?string, buttons: list<array{title: ?string, id: ?string, url: ?string}>, list_button: ?string, rows: list<array{id: ?string, title: ?string, description: ?string}>, body: ?string, reply_id: ?string, errors: list<string>}
     */
    private function outboundInteractiveExtras(array $payload, array $extras): array
    {
        $action = (array) ($payload['action'] ?? []);

        switch ($payload['type'] ?? null) {
            case 'button':
                $extras['chip'] = '🔘 Кнопки';

                foreach ((array) ($action['buttons'] ?? []) as $button) {
                    $title = $this->stringOrNull($button['reply']['title'] ?? null);
                    $id = $this->stringOrNull($button['reply']['id'] ?? null);

                    if ($title !== null || $id !== null) {
                        $extras['buttons'][] = ['title' => $title, 'id' => $id, 'url' => null];
                    }
                }
                break;

            case 'cta_url':
                $extras['chip'] = '🔗 Кнопка-ссылка';
                $parameters = (array) ($action['parameters'] ?? []);
                $title = $this->stringOrNull($parameters['display_text'] ?? null);
                $url = $this->stringOrNull($parameters['url'] ?? null);

                if ($title !== null || $url !== null) {
                    $extras['buttons'][] = ['title' => $title, 'id' => null, 'url' => $url];
                }
                break;

            case 'list':
                $extras['chip'] = '📑 Список';
                $extras['list_button'] = $this->stringOrNull($action['button'] ?? null);

                foreach ((array) ($action['sections'] ?? []) as $section) {
                    foreach ((array) ($section['rows'] ?? []) as $row) {
                        $title = $this->stringOrNull($row['title'] ?? null);
                        $id = $this->stringOrNull($row['id'] ?? null);

                        if ($title !== null || $id !== null) {
                            $extras['rows'][] = [
                                'id' => $id,
                                'title' => $title,
                                'description' => $this->stringOrNull($row['description'] ?? null),
                            ];
                        }
                    }
                }
                break;
        }

        return $extras;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{chip: ?string, buttons: list<array{title: ?string, id: ?string, url: ?string}>, list_button: ?string, rows: list<array{id: ?string, title: ?string, description: ?string}>, body: ?string, reply_id: ?string, errors: list<string>}  $extras
     * @return array{chip: ?string, buttons: list<array{title: ?string, id: ?string, url: ?string}>, list_button: ?string, rows: list<array{id: ?string, title: ?string, description: ?string}>, body: ?string, reply_id: ?string, errors: list<string>}
     */
    private function inboundInteractiveExtras(array $payload, array $extras): array
    {
        if (isset($payload['button_reply'])) {
            $extras['chip'] = '↩ Нажата кнопка';
            $extras['reply_id'] = $this->stringOrNull($payload['button_reply']['id'] ?? null);
        } elseif (isset($payload['list_reply'])) {
            $extras['chip'] = '↩ Выбран пункт списка';
            $extras['reply_id'] = $this->stringOrNull($payload['list_reply']['id'] ?? null);
        }

        return $extras;
    }

    /**
     * The send payload of a template holds only parameter values and
     * machine button payloads; the human text lives in the template row
     * ({{n}} placeholders in body, button titles in Meta-synced components).
     * Substituting one into the other restores what the contact saw.
     *
     * @param  array<string, mixed>  $payload
     * @param  array{chip: ?string, buttons: list<array{title: ?string, id: ?string, url: ?string}>, list_button: ?string, rows: list<array{id: ?string, title: ?string, description: ?string}>, body: ?string, reply_id: ?string, errors: list<string>}  $extras
     * @return array{chip: ?string, buttons: list<array{title: ?string, id: ?string, url: ?string}>, list_button: ?string, rows: list<array{id: ?string, title: ?string, description: ?string}>, body: ?string, reply_id: ?string, errors: list<string>}
     */
    private function templateExtras(ChannelMessage $message, array $payload, array $extras): array
    {
        $template = $message->template;
        $components = array_values((array) ($payload['components'] ?? []));

        if ($template !== null && filled($template->body)) {
            $extras['chip'] = '📋 Шаблон «'.$template->name.'»';

            $bodyComponent = array_find($components, fn (mixed $component): bool => is_array($component) && ($component['type'] ?? '') === 'body');

            // Одним проходом (strtr), а не последовательными заменами:
            // литеральный «{{n}}» внутри значения параметра не должен
            // подставляться повторно — контакт видел его как есть.
            $replacements = [];

            foreach (array_values((array) ($bodyComponent['parameters'] ?? [])) as $index => $parameter) {
                $replacements['{{'.($index + 1).'}}'] = $this->stringOrNull(((array) $parameter)['text'] ?? null) ?? '';
            }

            $extras['body'] = strtr($template->body, $replacements);
        }

        $titlesComponent = array_find(
            array_values((array) ($template?->components ?? [])),
            fn (mixed $component): bool => is_array($component) && strtoupper((string) ($component['type'] ?? '')) === 'BUTTONS',
        );
        $titles = array_values((array) ($titlesComponent['buttons'] ?? []));

        foreach ($components as $position => $component) {
            if (! is_array($component) || ($component['type'] ?? '') !== 'button') {
                continue;
            }

            $index = (int) ($component['index'] ?? $position);
            $title = $this->stringOrNull($titles[$index]['text'] ?? null);
            $id = $this->stringOrNull($component['parameters'][0]['payload'] ?? null);

            if ($title !== null || $id !== null) {
                $extras['buttons'][] = ['title' => $title, 'id' => $id, 'url' => null];
            }
        }

        return $extras;
    }

    /**
     * Meta delivery errors embedded in an inbound payload — e.g. an
     * «Unsupported webhook payload» instead of message content.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function payloadErrors(array $payload): array
    {
        $lines = [];

        foreach ((array) ($payload['errors'] ?? []) as $error) {
            $error = (array) $error;
            $title = $error['title'] ?? null;
            $details = $error['error_data']['details'] ?? $error['message'] ?? null;

            $line = trim(implode(': ', array_filter(
                [$this->stringOrNull($title), $details !== $title ? $this->stringOrNull($details) : null],
                fn (?string $part): bool => filled($part),
            )));

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Payload — внешние данные произвольной формы: где ждём строку, но
     * лежит мусор, показываем пусто, а не роняем весь тред TypeError'ом.
     */
    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * What the selected contact has cost so far — AI calls plus delivered
     * template messages — and the dialog's message counters.
     *
     * @return array{ai_cost: string, ai_unknown: int, template_cost: string, template_unknown: int, inbound: int, outbound: int, templates: int, failed: int}
     */
    public function contactTotals(): array
    {
        $ai = AiAttempt::query()
            ->join('ai_operations', 'ai_operations.id', '=', 'ai_attempts.ai_operation_id')
            ->where('ai_operations.contact_id', $this->contactId)
            ->selectRaw('coalesce(sum(ai_attempts.estimated_cost_usd), 0) as cost')
            ->selectRaw('count(*) filter (where ai_attempts.cost_status = ?) as unknown_cost', [AiCostStatus::Unknown->value])
            ->first();

        $billable = array_map(fn (ChannelMessageStatus $status): string => $status->value, self::BILLABLE_STATUSES);

        $messages = ChannelMessage::query()
            ->where('contact_id', $this->contactId)
            ->selectRaw('count(*) filter (where direction = ?) as inbound', [ChannelDirection::Inbound->value])
            ->selectRaw('count(*) filter (where direction = ?) as outbound', [ChannelDirection::Outbound->value])
            ->selectRaw("count(*) filter (where type = 'template') as templates")
            ->selectRaw('count(*) filter (where status = ?) as failed', [ChannelMessageStatus::Failed->value])
            ->selectRaw("coalesce(sum(estimated_cost_usd) filter (where type = 'template' and status in (?, ?)), 0) as template_cost", $billable)
            ->selectRaw("count(*) filter (where type = 'template' and cost_status = ? and status in (?, ?)) as template_unknown", [AiCostStatus::Unknown->value, ...$billable])
            ->first();

        return [
            'ai_cost' => number_format((float) $ai->cost, 4),
            'ai_unknown' => (int) $ai->unknown_cost,
            'template_cost' => number_format((float) $messages->template_cost, 4),
            'template_unknown' => (int) $messages->template_unknown,
            'inbound' => (int) $messages->inbound,
            'outbound' => (int) $messages->outbound,
            'templates' => (int) $messages->templates,
            'failed' => (int) $messages->failed,
        ];
    }
}
