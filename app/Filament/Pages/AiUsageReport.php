<?php

namespace App\Filament\Pages;

use App\Enums\AiAttemptStatus;
use App\Enums\AiCostStatus;
use App\Enums\AiOperationType;
use App\Enums\ChannelDirection;
use App\Enums\ChannelMessageAuthor;
use App\Enums\ChannelMessageStatus;
use App\Enums\WhatsappTemplateCategory;
use App\Models\AiAttempt;
use App\Models\ChannelMessage;
use App\Services\WhatsappCostEstimator;
use App\Support\DisplayTime;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Operator expense report: the AI audit journal (money by day, model,
 * function and contact, plus request counts, errors and latency) and the
 * bot's WhatsApp spend: template messages by category and session messages
 * beyond the monthly free tier of the business number (Meta bills per
 * delivered message — only delivered/read ones enter the sums). Money
 * columns are estimates from the stored tariff snapshots; calls or messages
 * without a configured tariff are counted separately as «без тарифа» (see
 * config/ai-pricing.php and config/whatsapp-pricing.php). The operator's
 * messages from the phone app are free and stay out of the money.
 */
class AiUsageReport extends Page
{
    /** Statuses Meta actually bills a message for. */
    private const BILLABLE_STATUSES = [ChannelMessageStatus::Delivered, ChannelMessageStatus::Read];

    protected static ?string $slug = 'ai-usage';

    protected string $view = 'filament.pages.ai-usage-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Расходы';

    protected static ?string $title = 'Расходы: AI и WhatsApp';

    protected static ?int $navigationSort = 5;

    #[Url]
    public int $days = 30;

    /** @var list<int> */
    public array $periods = [7, 30, 90];

    public function updatedDays(): void
    {
        if (! in_array($this->days, $this->periods, true)) {
            $this->days = 30;
        }
    }

    /**
     * @return array{requests: int, errors: int, avg_latency_ms: int|null, cost_usd: string, unknown_cost: int, input_tokens: int, output_tokens: int}
     */
    public function summary(): array
    {
        $row = $this->attempts()
            ->selectRaw('count(*) as requests')
            ->selectRaw('count(*) filter (where status = ?) as errors', [AiAttemptStatus::Failed->value])
            ->selectRaw('avg(latency_ms) as avg_latency')
            ->selectRaw('coalesce(sum(estimated_cost_usd), 0) as cost')
            ->selectRaw('count(*) filter (where cost_status = ?) as unknown_cost', [AiCostStatus::Unknown->value])
            ->selectRaw('coalesce(sum(input_tokens), 0) as input_tokens')
            ->selectRaw('coalesce(sum(output_tokens), 0) as output_tokens')
            ->first();

        return [
            'requests' => (int) $row->requests,
            'errors' => (int) $row->errors,
            'avg_latency_ms' => $row->avg_latency !== null ? (int) $row->avg_latency : null,
            'cost_usd' => number_format((float) $row->cost, 4),
            'unknown_cost' => (int) $row->unknown_cost,
            'input_tokens' => (int) $row->input_tokens,
            'output_tokens' => (int) $row->output_tokens,
        ];
    }

    /**
     * @return Collection<int, object>
     */
    public function byDay(): Collection
    {
        return $this->attempts()
            ->selectRaw($this->localDayExpression(), [DisplayTime::timezone()])
            ->selectRaw('count(*) as requests')
            ->selectRaw('count(*) filter (where status = ?) as errors', [AiAttemptStatus::Failed->value])
            ->selectRaw('coalesce(sum(input_tokens + output_tokens), 0) as tokens')
            ->selectRaw('coalesce(sum(estimated_cost_usd), 0) as cost')
            ->groupBy('day')
            ->orderByDesc('day')
            ->get();
    }

    /**
     * @return Collection<int, object>
     */
    public function byModel(): Collection
    {
        return $this->attempts()
            ->selectRaw("coalesce(model, '—') as model")
            ->selectRaw('count(*) as requests')
            ->selectRaw('count(*) filter (where status = ?) as errors', [AiAttemptStatus::Failed->value])
            ->selectRaw('avg(latency_ms) as avg_latency')
            ->selectRaw('coalesce(sum(input_tokens), 0) as input_tokens')
            ->selectRaw('coalesce(sum(output_tokens), 0) as output_tokens')
            ->selectRaw('coalesce(sum(estimated_cost_usd), 0) as cost')
            ->groupBy('model')
            ->orderByDesc('cost')
            ->get();
    }

    /**
     * @return Collection<int, object>
     */
    public function byOperation(): Collection
    {
        return $this->attempts()
            ->join('ai_operations', 'ai_operations.id', '=', 'ai_attempts.ai_operation_id')
            ->selectRaw('ai_operations.operation as operation')
            ->selectRaw('count(*) as requests')
            ->selectRaw('count(*) filter (where ai_attempts.status = ?) as errors', [AiAttemptStatus::Failed->value])
            ->selectRaw('avg(ai_attempts.latency_ms) as avg_latency')
            ->selectRaw('coalesce(sum(ai_attempts.estimated_cost_usd), 0) as cost')
            ->groupBy('ai_operations.operation')
            ->orderByDesc('cost')
            ->get()
            ->each(function (object $row): void {
                $row->label = AiOperationType::tryFrom($row->operation)?->getLabel() ?? $row->operation;
            });
    }

    /**
     * @return Collection<int, object>
     */
    public function topContacts(): Collection
    {
        return $this->attempts()
            ->join('ai_operations', 'ai_operations.id', '=', 'ai_attempts.ai_operation_id')
            ->join('contacts', 'contacts.id', '=', 'ai_operations.contact_id')
            ->selectRaw('contacts.phone as phone')
            ->selectRaw("max(coalesce(nullif(contacts.display_name, ''), contacts.profile_name, '')) as profile_name")
            ->selectRaw('count(*) as requests')
            ->selectRaw('coalesce(sum(ai_attempts.estimated_cost_usd), 0) as cost')
            ->groupBy('contacts.phone')
            ->orderByDesc('cost')
            ->limit(10)
            ->get();
    }

    /**
     * @return array{sent: int, billable: int, failed: int, cost_usd: string, unknown_cost: int}
     */
    public function whatsappSummary(): array
    {
        $billable = $this->billableStatusValues();

        $row = $this->templateMessages()
            ->selectRaw('count(*) as sent')
            ->selectRaw('count(*) filter (where status in (?, ?)) as billable', $billable)
            ->selectRaw('count(*) filter (where status = ?) as failed', [ChannelMessageStatus::Failed->value])
            ->selectRaw('coalesce(sum(estimated_cost_usd) filter (where status in (?, ?)), 0) as cost', $billable)
            ->selectRaw('count(*) filter (where cost_status = ? and status in (?, ?)) as unknown_cost', [AiCostStatus::Unknown->value, ...$billable])
            ->first();

        return [
            'sent' => (int) $row->sent,
            'billable' => (int) $row->billable,
            'failed' => (int) $row->failed,
            'cost_usd' => number_format((float) $row->cost, 4),
            'unknown_cost' => (int) $row->unknown_cost,
        ];
    }

    /**
     * The bot's session messages: how many were delivered, how many of them
     * fell beyond the monthly free tier, and what they cost.
     *
     * @return array{delivered: int, paid: int, cost_usd: string, unknown_cost: int}
     */
    public function sessionSummary(): array
    {
        $billable = $this->billableStatusValues();

        $row = $this->sessionMessages()
            ->selectRaw('count(*) filter (where status in (?, ?)) as delivered', $billable)
            ->selectRaw('count(*) filter (where status in (?, ?) and estimated_cost_usd > 0) as paid', $billable)
            ->selectRaw('coalesce(sum(estimated_cost_usd) filter (where status in (?, ?)), 0) as cost', $billable)
            ->selectRaw('count(*) filter (where cost_status = ? and status in (?, ?)) as unknown_cost', [AiCostStatus::Unknown->value, ...$billable])
            ->first();

        return [
            'delivered' => (int) $row->delivered,
            'paid' => (int) $row->paid,
            'cost_usd' => number_format((float) $row->cost, 4),
            'unknown_cost' => (int) $row->unknown_cost,
        ];
    }

    /**
     * Templates plus session messages — the whole WhatsApp spend of the period.
     */
    public function whatsappTotalCost(): string
    {
        $cost = $this->botMessages()
            ->whereIn('status', self::BILLABLE_STATUSES)
            ->sum('estimated_cost_usd');

        return number_format((float) $cost, 4);
    }

    /**
     * How much of the current month's free tier of session messages the
     * business number has used, or — while session messages are free
     * altogether — when the tier starts and what comes after it.
     *
     * @return array{limit: int|null, used: int, rate: float|null, next_from: string|null, next_limit: int|null, next_rate: float|null}
     */
    public function freeTier(): array
    {
        $estimator = app(WhatsappCostEstimator::class);
        $now = now();
        $current = $estimator->rateCardAt($now);
        $next = $estimator->nextRateCardAfter($now);

        return [
            'limit' => $current['service_free_tier'] ?? null,
            'rate' => isset($current['categories']['service']) ? (float) $current['categories']['service'] : null,
            'used' => $estimator->sessionMessagesInMonth($now),
            'next_from' => $next !== null ? CarbonImmutable::parse($next['effective_from'])->format('d.m.Y') : null,
            'next_limit' => $next['service_free_tier'] ?? null,
            'next_rate' => isset($next['categories']['service']) ? (float) $next['categories']['service'] : null,
        ];
    }

    /**
     * @return Collection<int, object>
     */
    public function whatsappByDay(): Collection
    {
        $billable = $this->billableStatusValues();

        return $this->botMessages()
            ->selectRaw($this->localDayExpression(), [DisplayTime::timezone()])
            ->selectRaw("count(*) filter (where type = 'template') as templates_sent")
            ->selectRaw("count(*) filter (where type = 'template' and status in (?, ?)) as templates_delivered", $billable)
            ->selectRaw("count(*) filter (where type <> 'template' and status in (?, ?)) as session_delivered", $billable)
            // Отказы шаблонов — как в карточке «Ошибок доставки»; все отказы
            // дня видны в таблице «Сообщения по дням».
            ->selectRaw("count(*) filter (where type = 'template' and status = ?) as templates_failed", [ChannelMessageStatus::Failed->value])
            ->selectRaw('coalesce(sum(estimated_cost_usd) filter (where status in (?, ?)), 0) as cost', $billable)
            ->groupBy('day')
            ->orderByDesc('day')
            ->get();
    }

    /**
     * @return Collection<int, object>
     */
    public function whatsappByTemplate(): Collection
    {
        $billable = $this->billableStatusValues();

        return $this->templateMessages()
            ->leftJoin('whatsapp_templates', 'whatsapp_templates.id', '=', 'channel_messages.whatsapp_template_id')
            ->selectRaw("coalesce(whatsapp_templates.name, '—') as name")
            ->selectRaw('whatsapp_templates.category as category')
            ->selectRaw('count(*) as sent')
            ->selectRaw('count(*) filter (where channel_messages.status in (?, ?)) as billable', $billable)
            ->selectRaw('coalesce(sum(channel_messages.estimated_cost_usd) filter (where channel_messages.status in (?, ?)), 0) as cost', $billable)
            ->groupBy('name', 'category')
            ->orderByDesc('cost')
            ->get()
            ->each(function (object $row): void {
                $row->category_label = $row->category !== null
                    ? (WhatsappTemplateCategory::tryFrom($row->category)?->getLabel() ?? $row->category)
                    : '—';
            });
    }

    /**
     * @return Collection<int, object>
     */
    public function messagesByDay(): Collection
    {
        return ChannelMessage::query()
            ->where('created_at', '>=', now()->subDays($this->days))
            ->selectRaw($this->localDayExpression(), [DisplayTime::timezone()])
            ->selectRaw('count(*) filter (where direction = ?) as inbound', [ChannelDirection::Inbound->value])
            ->selectRaw('count(*) filter (where direction = ?) as outbound', [ChannelDirection::Outbound->value])
            ->selectRaw("count(*) filter (where type = 'template') as templates")
            ->selectRaw('count(*) filter (where status = ?) as failed', [ChannelMessageStatus::Failed->value])
            ->groupBy('day')
            ->orderByDesc('day')
            ->get();
    }

    /**
     * The stored-UTC timestamp bucketed into operator-local days (bind the
     * display timezone): a nightly call at 21:00 UTC belongs to the next
     * Kazakhstan day, and a UTC date() would smear its cost across days.
     */
    protected function localDayExpression(): string
    {
        return "date(created_at at time zone 'UTC' at time zone ?) as day";
    }

    /**
     * @return Builder<AiAttempt>
     */
    protected function attempts(): Builder
    {
        return AiAttempt::query()->where('ai_attempts.created_at', '>=', now()->subDays($this->days));
    }

    /**
     * @return Builder<ChannelMessage>
     */
    protected function templateMessages(): Builder
    {
        return ChannelMessage::query()
            ->where('type', 'template')
            ->where('channel_messages.created_at', '>=', now()->subDays($this->days));
    }

    /**
     * @return Builder<ChannelMessage>
     */
    protected function sessionMessages(): Builder
    {
        return $this->botMessages()->where('type', '<>', 'template');
    }

    /**
     * Everything the bot sent through the API — what Meta bills for; the
     * operator's messages from the phone app are free.
     *
     * @return Builder<ChannelMessage>
     */
    protected function botMessages(): Builder
    {
        return ChannelMessage::query()
            ->where('direction', ChannelDirection::Outbound)
            ->where('author', ChannelMessageAuthor::Bot)
            ->where('channel_messages.created_at', '>=', now()->subDays($this->days));
    }

    /**
     * @return list<string>
     */
    protected function billableStatusValues(): array
    {
        return array_map(fn (ChannelMessageStatus $status): string => $status->value, self::BILLABLE_STATUSES);
    }
}
