<?php

namespace App\Services\Ai\Audit;

use App\Enums\AiCostStatus;

/**
 * Turns token usage into money by the tariff configured in
 * config/ai-pricing.php. The tariff snapshot is returned alongside so the
 * attempt stores what the estimate was based on. Unknown model, missing
 * tariff or a used token kind without a rate → cost_status=unknown, never
 * a silent zero (tokens are not universal credits).
 */
class AiCostEstimator
{
    /**
     * @return array{pricing_snapshot: array<string, float|null>|null, estimated_cost_usd: string|null, cost_status: AiCostStatus}
     */
    public function estimate(?string $model, int $inputTokens, int $outputTokens, int $cacheReadTokens = 0, int $cacheWriteTokens = 0): array
    {
        /** @var array{input?: float|null, output?: float|null, cache_read?: float|null, cache_write?: float|null}|null $tariff */
        $tariff = $model !== null ? config("ai-pricing.models.{$model}") : null;

        $unknown = [
            'pricing_snapshot' => $tariff,
            'estimated_cost_usd' => null,
            'cost_status' => AiCostStatus::Unknown,
        ];

        if ($tariff === null || $inputTokens + $outputTokens + $cacheReadTokens + $cacheWriteTokens === 0) {
            return $unknown;
        }

        $components = [
            ['tokens' => $inputTokens, 'rate' => $tariff['input'] ?? null],
            ['tokens' => $outputTokens, 'rate' => $tariff['output'] ?? null],
            ['tokens' => $cacheReadTokens, 'rate' => $tariff['cache_read'] ?? null],
            ['tokens' => $cacheWriteTokens, 'rate' => $tariff['cache_write'] ?? null],
        ];

        $cost = 0.0;

        foreach ($components as $component) {
            if ($component['tokens'] === 0) {
                continue;
            }

            if ($component['rate'] === null) {
                return $unknown;
            }

            $cost += $component['tokens'] * $component['rate'] / 1_000_000;
        }

        return [
            'pricing_snapshot' => $tariff,
            'estimated_cost_usd' => number_format($cost, 6, '.', ''),
            'cost_status' => AiCostStatus::Estimated,
        ];
    }
}
