<?php

namespace App\Services;

use App\Enums\BotScenarioTrigger;
use App\Enums\CustomerRequestStatus;
use App\Models\BotScenario;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Services\Bot\ScenarioRunner;

/**
 * Placing a customer request for a listing — the shared core of the two
 * surfaces where the customer picks an option: the chat list row and the
 * «Выбрать» button of the web catalog. Both create the same pending
 * request and notify the supplier the same way.
 */
class CustomerRequestPlacer
{
    public function __construct(
        private readonly ScenarioRunner $runner,
        private readonly CustomerRequestNotifier $notifier,
    ) {}

    /**
     * Creates the request and notifies the supplier. An earlier request
     * for the same listing still pending the supplier's answer is
     * returned as is — no duplicate row, no repeated notification
     * (callers tell the two apart via $request->wasRecentlyCreated).
     * Accepted and declined requests never block a new one: re-asking
     * after a decline is legitimate, equipment is never locked. Without
     * a unique index the guard is query-level — an acceptable MVP race,
     * since only chat traffic is serialized per contact.
     */
    public function place(Contact $customer, Listing $listing, string $queryText): CustomerRequest
    {
        $pending = CustomerRequest::query()
            ->where('contact_id', $customer->id)
            ->where('listing_id', $listing->id)
            ->where('status', CustomerRequestStatus::Pending)
            ->latest('id')
            ->first();

        if ($pending !== null) {
            return $pending;
        }

        $request = CustomerRequest::create([
            'contact_id' => $customer->id,
            'listing_id' => $listing->id,
            'query_text' => $queryText,
        ]);

        $this->notifySupplier($request);

        return $request;
    }

    /**
     * The published «Новая заявка» scenario orchestrates the supplier
     * notification as an isolated run; while none is published, the
     * legacy hardcoded notifier keeps the flow working.
     */
    protected function notifySupplier(CustomerRequest $request): void
    {
        $scenario = BotScenario::publishedForTrigger(BotScenarioTrigger::NewCustomerRequest);

        if ($scenario !== null) {
            $this->runner->launch($scenario, $request->listing->supplier, $request);

            return;
        }

        $this->notifier->notifySupplier($request);
    }
}
