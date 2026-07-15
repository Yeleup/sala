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
