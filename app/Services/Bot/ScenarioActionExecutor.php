<?php

namespace App\Services\Bot;

use App\Enums\CustomerRequestStatus;
use App\Enums\ScenarioAction;
use App\Exceptions\SessionWindowClosed;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\ScenarioRun;
use App\Services\Ai\CtaLinkBuilder;
use App\Services\DereuMessenger;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Executes «Действие» blocks by delegating to the domain: status guards,
 * final decisions and window limits stay in the models and services —
 * the scenario only decides when to call them. An action whose domain
 * precondition no longer holds (the request is already decided, the
 * listing is not published) is skipped: the graph's condition blocks are
 * the place to branch on that, and a race between two replies must not
 * crash the run.
 */
class ScenarioActionExecutor
{
    private const string CABINET_CTA_TEXT = 'Откройте кабинет, чтобы обновить или снять с публикации свои объявления.';

    private const string CABINET_CTA_BUTTON = 'Открыть кабинет';

    public function __construct(
        private readonly DereuMessenger $messenger,
        private readonly CtaLinkBuilder $links,
    ) {}

    /**
     * @param  array<string, mixed>  $node
     */
    public function execute(ScenarioRun $run, ScenarioAction $action, array $node): void
    {
        $subject = $run->subject;

        try {
            match ($action) {
                ScenarioAction::AcceptRequest => $subject instanceof CustomerRequest ? $subject->accept() : null,
                ScenarioAction::DeclineRequest => $subject instanceof CustomerRequest ? $subject->decline() : null,
                ScenarioAction::RenewListing => $subject instanceof Listing ? $subject->renew() : null,
                ScenarioAction::ArchiveListing => $subject instanceof Listing ? $subject->archive() : null,
                ScenarioAction::SendCabinetCta => $this->sendCabinetCta($run, $node),
                ScenarioAction::NotifyCustomer => $subject instanceof CustomerRequest ? $this->notifyCustomer($subject) : null,
            };
        } catch (LogicException $e) {
            Log::warning('Scenario action skipped — the domain precondition no longer holds.', [
                'scenario_run_id' => $run->id,
                'action' => $action->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    protected function sendCabinetCta(ScenarioRun $run, array $node): void
    {
        $this->messenger->sendCtaUrl(
            $run->contact,
            (string) ($node['text'] ?? '') ?: self::CABINET_CTA_TEXT,
            self::CABINET_CTA_BUTTON,
            $this->links->myListingsUrl($run->contact),
        );
    }

    /**
     * Best effort: the customer's own 24-hour window may already be closed
     * (the supplier answered days later) — then the outcome stays visible
     * to the operator only. An MVP trade-off, no paid template for it.
     */
    protected function notifyCustomer(CustomerRequest $request): void
    {
        $category = $request->listing->category?->name;
        $phone = ltrim($request->listing->supplier->phone, '+');

        $text = match ($request->status) {
            CustomerRequestStatus::Accepted => $category
                ? sprintf('Поставщик согласился по вашей заявке («%s»). Свяжитесь с ним: +%s', $category, $phone)
                : sprintf('Поставщик согласился по вашей заявке. Свяжитесь с ним: +%s', $phone),
            CustomerRequestStatus::Declined => 'К сожалению, поставщик отказался по вашей заявке. Напишите нам — подберём другие варианты.',
            CustomerRequestStatus::Pending => null,
        };

        if ($text === null) {
            return;
        }

        try {
            $this->messenger->sendText($request->customer, $text);
        } catch (SessionWindowClosed) {
            Log::info('Customer window closed — the request outcome was not delivered.', [
                'customer_request_id' => $request->id,
            ]);
        }
    }
}
