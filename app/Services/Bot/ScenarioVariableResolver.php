<?php

namespace App\Services\Bot;

use App\Enums\ScenarioVariable;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\ScenarioRun;
use Illuminate\Support\Str;

/**
 * Resolves scenario variables from a run's subject (the request or the
 * listing) and its contact at send time.
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

        $value = match ($variable) {
            ScenarioVariable::ListingCategory => $listing?->category ?: 'без категории',
            ScenarioVariable::ListingDescription => $listing?->description,
            ScenarioVariable::ListingLocation => $listing?->location,
            ScenarioVariable::ListingPrice => $listing?->price,
            ScenarioVariable::RequestQuery => $subject instanceof CustomerRequest ? $subject->query_text : null,
            ScenarioVariable::ContactName => $run->contact->profile_name,
            ScenarioVariable::ContactPhone => '+'.ltrim($run->contact->phone, '+'),
        };

        return Str::limit(trim((string) $value), self::VALUE_LIMIT);
    }

    /**
     * Values for the {{n}} template placeholders, in the node's order.
     *
     * @param  list<string>  $variableKeys
     * @return list<string>
     */
    public function values(ScenarioRun $run, array $variableKeys): array
    {
        return array_map(
            fn (string $key): string => ($variable = ScenarioVariable::tryFrom($key)) !== null
                ? $this->resolve($run, $variable)
                : '',
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
