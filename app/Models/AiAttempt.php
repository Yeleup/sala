<?php

namespace App\Models;

use App\Enums\AiAttemptStatus;
use App\Enums\AiCostStatus;
use Database\Factories\AiAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One real provider request (including retries and failovers) with token
 * usage and the money estimated from the pricing snapshot taken at call
 * time. cost_status=unknown means «тарифа нет», never a silent zero.
 */
#[Fillable([
    'ai_operation_id', 'status', 'provider', 'model', 'invocation_id',
    'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'reasoning_tokens',
    'latency_ms', 'prompt', 'response', 'error', 'parameters', 'pricing_snapshot',
    'estimated_cost_usd', 'cost_status',
])]
class AiAttempt extends Model
{
    /** @use HasFactory<AiAttemptFactory> */
    use HasFactory;

    /** @return BelongsTo<AiOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(AiOperation::class, 'ai_operation_id');
    }

    /**
     * @return array{status: class-string<AiAttemptStatus>, cost_status: class-string<AiCostStatus>, parameters: 'array', pricing_snapshot: 'array', estimated_cost_usd: 'decimal:6'}
     */
    protected function casts(): array
    {
        return [
            'status' => AiAttemptStatus::class,
            'cost_status' => AiCostStatus::class,
            'parameters' => 'array',
            'pricing_snapshot' => 'array',
            'estimated_cost_usd' => 'decimal:6',
        ];
    }
}
