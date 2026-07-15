<?php

namespace App\Services;

use App\Enums\AiCostStatus;
use App\Enums\WhatsappTemplateCategory;

/**
 * Turns a template send into money by the tariff configured in
 * config/whatsapp-pricing.php (USD per delivered message, keyed by Meta
 * category). The tariff snapshot is returned alongside so the journal row
 * stores what the estimate was based on. Missing category or tariff →
 * cost_status=unknown, never a silent zero.
 */
class WhatsappCostEstimator
{
    /**
     * @return array{pricing_snapshot: array{category: string, per_delivered_usd: float}|null, estimated_cost_usd: string|null, cost_status: AiCostStatus}
     */
    public function estimate(?WhatsappTemplateCategory $category): array
    {
        $rate = $category !== null ? config("whatsapp-pricing.categories.{$category->value}") : null;

        if ($category === null || $rate === null) {
            return [
                'pricing_snapshot' => null,
                'estimated_cost_usd' => null,
                'cost_status' => AiCostStatus::Unknown,
            ];
        }

        return [
            'pricing_snapshot' => ['category' => $category->value, 'per_delivered_usd' => (float) $rate],
            'estimated_cost_usd' => number_format((float) $rate, 6, '.', ''),
            'cost_status' => AiCostStatus::Estimated,
        ];
    }
}
