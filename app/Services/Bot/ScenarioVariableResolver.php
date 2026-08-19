<?php

namespace App\Services\Bot;

use App\Enums\ScenarioVariable;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\ListingRenewalBatch;
use App\Models\ScenarioRun;
use Illuminate\Support\Str;

/**
 * Resolves scenario variables from a run's subject (the request, the
 * listing or the batch of expiring listings) and its contact at send
 * time.
 *
 * Two placeholder styles exist: template bodies use Meta's indexed {{n}}
 * mapped by the message block's ordered "variables" list, while session
 * texts of run-based scenarios may embed the named keys directly —
 * «{{listing.category}}» — so the operator sees what goes where.
 */
class ScenarioVariableResolver
{
    /** Keeps template parameters within Meta's sane length limits. */
    private const int VALUE_LIMIT = 200;

    public function resolve(ScenarioRun $run, ScenarioVariable $variable): string
    {
        $subject = $run->subject;
        $listing = match (true) {
            $subject instanceof Listing => $subject,
            $subject instanceof CustomerRequest => $subject->listing,
            default => null,
        };

        // Every branch must produce a non-empty value: Meta rejects a
        // template with an empty text parameter, which would kill the whole
        // send — a wordy placeholder beats an undelivered notification.
        $value = match ($variable) {
            ScenarioVariable::ListingTitle => $listing?->displayName() ?: 'без названия',
            ScenarioVariable::ListingCategory => $listing?->category?->name ?: 'без категории',
            ScenarioVariable::ListingDescription => $listing?->description ?: 'без описания',
            ScenarioVariable::ListingLocation => ($listing?->locationLine() ?: null) ?? 'место не указано',
            ScenarioVariable::ListingPrice => $listing?->price ?: 'цена не указана',
            // «12 ваших объявлений»: родительный падеж — его требует
            // формулировка «У {{1}} скоро закончится срок показа».
            ScenarioVariable::ExpiringListings => $subject instanceof ListingRenewalBatch
                ? ListingRenewalBatch::countPhrase($subject->listings()->count())
                : 'ваших объявлений',
            ScenarioVariable::RequestQuery => ($subject instanceof CustomerRequest ? $subject->query_text : null) ?: 'без уточнений',
            ScenarioVariable::RequestCustomer => $subject instanceof CustomerRequest ? $this->customerLine($subject->customer) : null,
            // The profile name is often absent (Dereu drops it from the
            // forward) — the phone is the one thing a contact always has.
            ScenarioVariable::ContactName => $run->contact->displayName() ?: '+'.ltrim($run->contact->phone, '+'),
            ScenarioVariable::ContactPhone => '+'.ltrim($run->contact->phone, '+'),
        };

        $value = Str::limit(trim((string) $value), self::VALUE_LIMIT);

        // The last-resort guard for holes the match cannot rule out (a
        // request variable on a listing run): a dash still delivers.
        return $value === '' ? '—' : $value;
    }

    /**
     * «Асель, +7700…» of the request's author — deliberately not the run's
     * contact, which is the supplier receiving the notification. The name is
     * capped within VALUE_LIMIT (minus the «...» a truncation appends) so the
     * trailing phone — the payload the supplier calls — always survives.
     */
    private function customerLine(Contact $customer): string
    {
        $phone = '+'.ltrim($customer->phone, '+');
        $name = trim((string) $customer->displayName());

        if ($name === '') {
            return $phone;
        }

        return Str::limit($name, self::VALUE_LIMIT - Str::length(", {$phone}") - 3).", {$phone}";
    }

    /**
     * Values for the {{n}} template placeholders, in the node's order.
     *
     * @param  list<string>  $variableKeys
     * @return list<string>
     */
    public function values(ScenarioRun $run, array $variableKeys): array
    {
        // An unknown key (a stale scenario after a variable was renamed)
        // resolves to a dash: an empty parameter would make Meta reject
        // the whole template send.
        return array_map(
            fn (string $key): string => ($variable = ScenarioVariable::tryFrom($key)) !== null
                ? $this->resolve($run, $variable)
                : '—',
            array_values($variableKeys),
        );
    }

    /**
     * The session variant of a template-sourced message block: the
     * template body with its indexed {{n}} placeholders replaced by the
     * mapped values — inside the window the contact sees exactly the
     * template wording, delivered as a free session message.
     *
     * @param  list<string>  $variableKeys
     */
    public function renderTemplateBody(ScenarioRun $run, string $body, array $variableKeys): string
    {
        $values = $this->values($run, $variableKeys);

        return (string) preg_replace_callback(
            '/\{\{\s*(\d+)\s*\}\}/',
            fn (array $matches): string => $values[(int) $matches[1] - 1] ?? $matches[0],
            $body,
        );
    }

    /**
     * Substitutes named «{{listing.category}}» placeholders in a session
     * text; unknown placeholders stay as typed.
     */
    public function substitute(ScenarioRun $run, string $text): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_.]+)\s*\}\}/',
            function (array $matches) use ($run): string {
                $variable = ScenarioVariable::tryFrom($matches[1]);

                return $variable === null ? $matches[0] : $this->resolve($run, $variable);
            },
            $text,
        );
    }
}
