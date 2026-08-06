<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\ValueObjects\CountrySignals;

/**
 * Whether a sale's subdivision is recorded, and which one.
 *
 * ## Why this is carried at all
 *
 * A US sales-tax nexus is measured PER STATE, and the threshold is a rolling window — so the question
 * "have we crossed it in Texas" can only be answered from history. The place evidence is written once at
 * the sale and the raw IP behind it is discarded on purpose, which means a state not captured at the sale
 * cannot be reconstructed afterwards from anything. A counter built later could only ever fill an `unknown`
 * bucket while looking as though it worked, and a nexus warning that starts counting when the market opens
 * warns after the threshold rather than before it.
 *
 * ## Why it is this narrow
 *
 * Everything about the evidence is deliberately coarse: the raw IP is thrown away, only a country is kept,
 * and nothing finer is asked for. A subdivision is one step finer than that, so it is carried only where a
 * question actually needs it:
 *
 *  - only for a country in `billing.tax_evidence.subdivision_countries` — the shipped list is the US alone,
 *    so nothing changes for any other sale;
 *  - only from the sources that named THAT country, and only when they agree;
 *  - only the ISO 3166-2 suffix, never a postcode, a city or a coordinate;
 *  - never invented — a consumer who supplies no subdivision signal records nothing, because this package
 *    has no finer input than the country and does not go looking for one.
 *
 * `billing.tax_evidence.collect_subdivision` switches the whole thing off without touching the rest of the
 * evidence, for an operator whose reading of data minimization differs. Off, the counter runs honestly on
 * `unknown` instead of quietly on a guess.
 */
final readonly class ResolvedSubdivision
{
    public function __construct(private Repository $config) {}

    /** The subdivision to record for a sale settled on this country, or null. */
    public function resolve(string $country, CountrySignals $signals): ?string
    {
        if ($this->config->get('billing.tax_evidence.collect_subdivision', true) !== true) {
            return null;
        }

        return $this->inScope($country) ? $signals->subdivisionFor($country) : null;
    }

    /**
     * Whether this country's subdivisions are worth recording.
     *
     * A list rather than "everywhere a subdivision exists", because almost every country has subdivisions
     * and almost none of them decide anything this package is asked about. Recording them all would be
     * collection with no question behind it — which is the objection this whole class is answering.
     */
    private function inScope(string $country): bool
    {
        $configured = $this->config->get('billing.tax_evidence.subdivision_countries', ['US']);
        $countries = is_array($configured) ? $configured : ['US'];

        $upper = array_map(
            static fn (mixed $code): string => is_string($code) ? strtoupper($code) : '',
            $countries,
        );

        return in_array(strtoupper($country), $upper, true);
    }
}
