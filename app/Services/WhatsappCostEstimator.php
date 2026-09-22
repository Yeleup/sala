<?php

namespace App\Services;

use App\Enums\AiCostStatus;
use App\Enums\ChannelDirection;
use App\Enums\ChannelMessageAuthor;
use App\Enums\ChannelMessageStatus;
use App\Enums\WhatsappTemplateCategory;
use App\Models\ChannelMessage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Turns a bot's WhatsApp send into money by the rate card configured in
 * config/whatsapp-pricing.php (USD per delivered message, keyed by Meta
 * category) that was in force at the moment of sending. The tariff
 * snapshot is returned alongside so the journal row stores what the
 * estimate was based on. Missing category, rate card or tariff →
 * cost_status=unknown, never a silent zero.
 *
 * Templates are priced by their category. Session (non-template) messages
 * are priced as Meta's «service» category after the monthly free tier of
 * the business number is used up: the message's position among this
 * month's session messages decides whether it is free.
 */
class WhatsappCostEstimator
{
    /**
     * @return array{pricing_snapshot: array{category: string, per_delivered_usd: float, rate_card: string}|null, estimated_cost_usd: string|null, cost_status: AiCostStatus}
     */
    public function estimate(?WhatsappTemplateCategory $category, ?CarbonInterface $at = null): array
    {
        $card = $this->rateCardAt($at ?? now());
        $rate = $category !== null ? ($card['categories'][$category->value] ?? null) : null;

        if ($card === null || $category === null || $rate === null) {
            return $this->unknown();
        }

        return $this->estimated(
            ['category' => $category->value, 'per_delivered_usd' => (float) $rate, 'rate_card' => $card['effective_from']],
            (float) $rate,
        );
    }

    /**
     * A session message the bot sends inside the contact's 24-hour window.
     * Call it before journaling the message: the position counts the
     * month's session messages already in the journal plus this one.
     *
     * @return array{pricing_snapshot: array{category: string, per_delivered_usd: float, rate_card: string, free_tier?: int, position_in_month?: int}|null, estimated_cost_usd: string|null, cost_status: AiCostStatus}
     */
    public function estimateSession(?CarbonInterface $at = null): array
    {
        $at ??= now();
        $card = $this->rateCardAt($at);
        $rate = $card['categories']['service'] ?? null;

        if ($card === null || $rate === null) {
            return $this->unknown();
        }

        $snapshot = ['category' => 'service', 'per_delivered_usd' => (float) $rate, 'rate_card' => $card['effective_from']];

        if ($card['service_free_tier'] === null) {
            return $this->estimated($snapshot, (float) $rate);
        }

        $positionInMonth = $this->sessionMessagesInMonth($at) + 1;

        return $this->estimated(
            [...$snapshot, 'free_tier' => $card['service_free_tier'], 'position_in_month' => $positionInMonth],
            $positionInMonth > $card['service_free_tier'] ? (float) $rate : 0.0,
        );
    }

    /**
     * The bot's session messages of the calendar month (WABA timezone) that
     * contains the given moment — what the free tier is spent on. Meta
     * counts delivered messages; failed ones are left out here, and the few
     * still in flight are counted as if they will arrive. The operator's
     * messages from the phone app are free and never count.
     */
    public function sessionMessagesInMonth(CarbonInterface $at): int
    {
        $monthStart = CarbonImmutable::instance($at)->setTimezone($this->timezone())->startOfMonth();

        return ChannelMessage::query()
            ->where('direction', ChannelDirection::Outbound)
            ->where('author', ChannelMessageAuthor::Bot)
            ->where('type', '!=', 'template')
            ->where('status', '!=', ChannelMessageStatus::Failed)
            ->where('created_at', '>=', $this->storageTime($monthStart))
            ->where('created_at', '<', $this->storageTime($monthStart->addMonth()))
            ->count();
    }

    /**
     * A free-tier session message that failed gives its slot back. Meta
     * counts delivered messages only, while the message was counted as if it
     * would arrive — so the first message of the same month stamped as paid
     * after it becomes free. Messages sent after the failure need nothing:
     * their count already skips it. Call once, when the message turns failed.
     */
    public function releaseFreeSlot(ChannelMessage $failed): void
    {
        $snapshot = (array) $failed->pricing_snapshot;

        if ($failed->author !== ChannelMessageAuthor::Bot
            || $failed->type === 'template'
            || ! isset($snapshot['free_tier'], $snapshot['position_in_month'])
            || $snapshot['position_in_month'] > $snapshot['free_tier']) {
            return;
        }

        $monthStart = CarbonImmutable::instance($failed->created_at)->setTimezone($this->timezone())->startOfMonth();

        DB::transaction(function () use ($failed, $monthStart): void {
            $firstPaid = ChannelMessage::query()
                ->where('direction', ChannelDirection::Outbound)
                ->where('author', ChannelMessageAuthor::Bot)
                ->where('type', '!=', 'template')
                ->where('status', '!=', ChannelMessageStatus::Failed)
                ->where('cost_status', AiCostStatus::Estimated)
                ->where('estimated_cost_usd', '>', 0)
                ->where('id', '>', $failed->id)
                ->where('created_at', '<', $this->storageTime($monthStart->addMonth()))
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            $firstPaid?->update([
                'estimated_cost_usd' => number_format(0, 6, '.', ''),
                'pricing_snapshot' => [...(array) $firstPaid->pricing_snapshot, 'freed_by_failed_message_id' => $failed->id],
            ]);
        });
    }

    /**
     * The rate card in force at the given moment: the latest one whose
     * effective date (midnight, WABA timezone) has come.
     *
     * @return array{effective_from: string, categories: array<string, float|null>, service_free_tier: int|null}|null
     */
    public function rateCardAt(CarbonInterface $at): ?array
    {
        $inForce = null;

        foreach ($this->rateCards() as $effectiveFrom => $card) {
            if ($this->effectiveMoment($effectiveFrom)->lessThanOrEqualTo($at)) {
                $inForce = $this->normalizeCard($effectiveFrom, $card);
            }
        }

        return $inForce;
    }

    /**
     * The first rate card that is not yet in force at the given moment.
     *
     * @return array{effective_from: string, categories: array<string, float|null>, service_free_tier: int|null}|null
     */
    public function nextRateCardAfter(CarbonInterface $at): ?array
    {
        foreach ($this->rateCards() as $effectiveFrom => $card) {
            if ($this->effectiveMoment($effectiveFrom)->greaterThan($at)) {
                return $this->normalizeCard($effectiveFrom, $card);
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>> Sorted by effective date.
     */
    protected function rateCards(): array
    {
        $cards = (array) config('whatsapp-pricing.rate_cards', []);
        ksort($cards, SORT_STRING);

        return $cards;
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array{effective_from: string, categories: array<string, float|null>, service_free_tier: int|null}
     */
    protected function normalizeCard(string $effectiveFrom, array $card): array
    {
        return [
            'effective_from' => $effectiveFrom,
            'categories' => (array) ($card['categories'] ?? []),
            'service_free_tier' => isset($card['service_free_tier']) ? (int) $card['service_free_tier'] : null,
        ];
    }

    protected function effectiveMoment(string $effectiveFrom): CarbonImmutable
    {
        return CarbonImmutable::parse($effectiveFrom, $this->timezone())->startOfDay();
    }

    /**
     * Query bindings are formatted in the date's own timezone, while
     * created_at is stored in the application one.
     */
    protected function storageTime(CarbonImmutable $moment): CarbonImmutable
    {
        return $moment->setTimezone((string) config('app.timezone'));
    }

    protected function timezone(): string
    {
        return (string) config('whatsapp-pricing.timezone', 'UTC');
    }

    /**
     * @return array{pricing_snapshot: null, estimated_cost_usd: null, cost_status: AiCostStatus}
     */
    protected function unknown(): array
    {
        return [
            'pricing_snapshot' => null,
            'estimated_cost_usd' => null,
            'cost_status' => AiCostStatus::Unknown,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{pricing_snapshot: array<string, mixed>, estimated_cost_usd: string, cost_status: AiCostStatus}
     */
    protected function estimated(array $snapshot, float $cost): array
    {
        return [
            'pricing_snapshot' => $snapshot,
            'estimated_cost_usd' => number_format($cost, 6, '.', ''),
            'cost_status' => AiCostStatus::Estimated,
        ];
    }
}
