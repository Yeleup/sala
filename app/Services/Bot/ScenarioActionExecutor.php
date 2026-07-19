<?php

namespace App\Services\Bot;

use App\Enums\CustomerRequestStatus;
use App\Enums\ScenarioAction;
use App\Enums\ScenarioActionOutcome;
use App\Exceptions\SessionWindowClosed;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\ScenarioRun;
use App\Services\Ai\CtaLinkBuilder;
use App\Services\DereuMessenger;
use Closure;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Executes «Действие» blocks by delegating to the domain: status guards,
 * final decisions and window limits stay in the models and services —
 * the scenario only decides when to call them. An action whose domain
 * precondition no longer holds (the request is already decided, the
 * listing is not published) reports Skipped, and the runner follows the
 * block's «skipped» output — a race between two replies must not crash
 * the run. Best-effort actions (CTA link, customer notification) never
 * report Skipped: «did less than intended» keeps the continue branch.
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
    public function execute(ScenarioRun $run, ScenarioAction $action, array $node): ScenarioActionOutcome
    {
        $subject = $run->subject;

        try {
            $done = match ($action) {
                ScenarioAction::AcceptRequest => $this->attempt($subject instanceof CustomerRequest, fn () => $subject->accept()),
                ScenarioAction::DeclineRequest => $this->attempt($subject instanceof CustomerRequest, fn () => $subject->decline()),
                ScenarioAction::RenewListing => $this->attempt($subject instanceof Listing, fn () => $subject->renew()),
                ScenarioAction::ArchiveListing => $this->attempt($subject instanceof Listing, fn () => $subject->archive()),
                ScenarioAction::SendCabinetCta => $this->attempt(true, fn () => $this->sendCabinetCta($run, $node)),
                ScenarioAction::NotifyCustomer => $this->attempt(true, fn () => $subject instanceof CustomerRequest ? $this->notifyCustomer($subject) : null),
            };
        } catch (LogicException $e) {
            Log::warning('Scenario action skipped — the domain precondition no longer holds.', [
                'scenario_run_id' => $run->id,
                'action' => $action->value,
                'error' => $e->getMessage(),
            ]);

            return $action->hasPrecondition() ? ScenarioActionOutcome::Skipped : ScenarioActionOutcome::Done;
        }

        return $done ? ScenarioActionOutcome::Done : ScenarioActionOutcome::Skipped;
    }

    /** Прочие исключения (транспорт, БД) летят выше — запуск падает в fail(). */
    protected function attempt(bool $preconditionMet, Closure $do): bool
    {
        if (! $preconditionMet) {
            return false;
        }

        $do();

        return true;
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
        $name = $request->listing->displayName();
        $phone = ltrim($request->listing->supplier->phone, '+');

        $text = match ($request->status) {
            CustomerRequestStatus::Accepted => $name
                ? sprintf('Поставщик согласился по вашей заявке («%s»). Свяжитесь с ним: +%s', $name, $phone)
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
