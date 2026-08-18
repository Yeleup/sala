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
     * for the same listing, still pending the answer of the listing's
     * current owner, is returned as is — no duplicate row, no repeated
     * notification (callers tell the two apart via
     * $request->wasRecentlyCreated). A pending request addressed to a
     * previous owner (the listing changed hands) never blocks: that
     * supplier no longer decides, so the new owner is notified afresh.
     * Accepted, declined and expired requests never block a new one:
     * re-asking is legitimate, equipment is never locked. Without a
     * unique index the guard is query-level — an acceptable MVP race,
     * since only chat traffic is serialized per contact.
     *
     * A notification that provably did not reach the supplier closes the
     * request as Expired right away — an unanswered ping must not turn
     * into a forever-pending row that silences every retry.
     */
    public function place(Contact $customer, Listing $listing, string $queryText): CustomerRequest
    {
        $pending = CustomerRequest::query()
            ->where('contact_id', $customer->id)
            ->where('listing_id', $listing->id)
            ->awaitingCurrentOwner()
            ->latest('id')
            ->first();

        if ($pending !== null) {
            return $pending;
        }

        $request = CustomerRequest::create([
            'contact_id' => $customer->id,
            'listing_id' => $listing->id,
            'supplier_contact_id' => $listing->contact_id,
            'query_text' => $queryText,
        ]);

        if (! $this->notifySupplier($request)) {
            $request->expire();
        }

        return $request;
    }

    /**
     * The published «Новая заявка» scenario orchestrates the supplier
     * notification as an isolated run; while none is published, the
     * legacy hardcoded notifier keeps the flow working. False — the
     * supplier was not reached (failed run, closed window without an
     * approved template, transport error).
     */
    protected function notifySupplier(CustomerRequest $request): bool
    {
        $scenario = BotScenario::publishedForTrigger(BotScenarioTrigger::NewCustomerRequest);

        if ($scenario !== null) {
            return $this->runner->launch($scenario, $request->listing->supplier, $request) !== null;
        }

        return $this->notifier->notifySupplier($request);
    }
}
