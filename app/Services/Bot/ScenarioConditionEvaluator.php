<?php

namespace App\Services\Bot;

use App\Enums\CustomerRequestStatus;
use App\Enums\ListingStatus;
use App\Enums\ScenarioCondition;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\ScenarioRun;

/**
 * Evaluates «Условие» blocks against the current domain state — never
 * against the state at send time, so a button clicked days later checks
 * what is true now (the request may be decided, the listing archived).
 */
class ScenarioConditionEvaluator
{
    public function evaluate(ScenarioRun $run, ScenarioCondition $condition): bool
    {
        $subject = $run->subject;

        return match ($condition) {
            ScenarioCondition::RequestPending => $subject instanceof CustomerRequest
                && $subject->status === CustomerRequestStatus::Pending,
            ScenarioCondition::ListingPublished => $subject instanceof Listing
                && $subject->status === ListingStatus::Published,
            ScenarioCondition::WindowOpen => $run->contact->hasOpenSessionWindow(),
        };
    }
}
