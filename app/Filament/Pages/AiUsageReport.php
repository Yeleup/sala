<?php

namespace App\Filament\Pages;

use App\Enums\AiAttemptStatus;
use App\Enums\AiCostStatus;
use App\Enums\AiOperationType;
use App\Models\AiAttempt;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Operator report over the AI audit journal: money by day, model,
 * function and contact, plus request counts, errors and latency. The
 * money column is an estimate from the stored tariff snapshots; calls
 * whose model has no configured tariff are counted separately as
 * «без тарифа» (see config/ai-pricing.php).
 */
class AiUsageReport extends Page
{
    protected static ?string $slug = 'ai-usage';

    protected string $view = 'filament.pages.ai-usage-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Отчёт AI';

    protected static ?string $title = 'AI: запросы и расходы';

    protected static ?int $navigationSort = 8;

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
            ->selectRaw("count(*) filter (where status = ?) as errors", [AiAttemptStatus::Failed->value])
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
            ->selectRaw('date(created_at) as day')
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
            ->selectRaw("max(coalesce(contacts.profile_name, '')) as profile_name")
            ->selectRaw('count(*) as requests')
            ->selectRaw('coalesce(sum(ai_attempts.estimated_cost_usd), 0) as cost')
            ->groupBy('contacts.phone')
            ->orderByDesc('cost')
            ->limit(10)
            ->get();
    }

    /**
     * @return Builder<AiAttempt>
     */
    protected function attempts(): Builder
    {
        return AiAttempt::query()->where('ai_attempts.created_at', '>=', now()->subDays($this->days));
    }
}
