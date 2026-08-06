<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonInterface;
use InvalidArgumentException;
use Pushery\Billing\Enums\TaxRateCategory;
use Pushery\Billing\Exceptions\UnknownTaxCountry;

/**
 * Which rate applies to a supply, from the destination country AND what was sold — the two-dimensional
 * answer a one-dimensional country table cannot give.
 *
 * A table keyed by country alone can only ever charge one rate per country, so a supply a country taxes at
 * a reduced rate is charged the standard one. That is not a rounding difference: on a worked example the
 * buyer's price is the same either way, and the whole difference lands on the seller's payout, which comes
 * out over ten percent short with nothing anywhere looking wrong.
 *
 * ## The reduced band is granted, never assumed
 *
 * A country that grants no reduced band for a supply is charged its standard rate — an absence in the table
 * means "not granted here", which is an answer. What is NOT an answer is an unknown country: that is refused
 * rather than priced, because every wrong number a missing country could produce is a number that
 * under-declares tax, and none of them look wrong on the invoice.
 *
 * ## The audio-visual gate is hard, and it is an OUTCOME rather than a warning
 *
 * Any audio or video component closes the reduced band for the whole supply. There is no majority test and
 * no threshold — a rule with a proportion in it is a rule that gets argued over per product, and the
 * argument is settled after the invoices are out. A caller asking for the reduced band on a supply carrying
 * such a component gets the standard rate back as the answer; nothing is flagged for someone to notice,
 * because the number is what ends up on the document either way. Splitting the product is how a seller gets
 * the reduced band, and that is a decision they make before selling, not one this resolves for them.
 *
 * ## Rates are data with an age
 *
 * A rate is a property of a country at a moment, and countries move theirs. A table with no issue date ages
 * silently and keeps answering with the confidence of the day it was written, so this one carries the date
 * it was valid from and can say how old it is — the check that acts on that lives with the configuration
 * that loads it.
 *
 * Nothing national lives here. The table is data handed in; a jurisdiction supplies its own, and this maps
 * the code's own category vocabulary onto it without reading any law.
 */
final readonly class TaxRateMatrix
{
    /**
     * @param  array<string, array<string, int>>  $rates  country → category → rate in basis points
     * @param  CarbonInterface  $validFrom  the day this table was known to be correct
     */
    public function __construct(
        private array $rates,
        private CarbonInterface $validFrom,
    ) {
        foreach ($rates as $country => $bands) {
            if (! isset($bands[TaxRateCategory::Standard->value])) {
                throw new InvalidArgumentException(
                    "The rate table entry for '{$country}' has no standard band. Every country in the table "
                    .'must have one: the standard rate is what a supply falls back to when a reduced band '
                    .'does not apply, so an entry without it can answer nothing.'
                );
            }

            foreach ($bands as $band => $bps) {
                if ($bps < 0 || $bps > 10_000) {
                    throw new InvalidArgumentException(
                        "The '{$band}' rate for '{$country}' is {$bps} basis points, outside 0 to 10000."
                    );
                }
            }
        }
    }

    /**
     * The rate to charge, in basis points.
     *
     * @param  bool  $hasAudioVisualComponent  any audio or video part of the supply, however small
     */
    public function rateFor(
        string $country,
        TaxRateCategory $category = TaxRateCategory::Standard,
        bool $hasAudioVisualComponent = false,
    ): int {
        // Upper-cased for the same reason the shipped calculator does it: the table is keyed by canonical
        // codes, and a lower-case code missing the table would land on the refusal below rather than on the
        // country the caller plainly meant.
        $bands = $this->rates[strtoupper($country)] ?? null;

        if ($bands === null) {
            throw UnknownTaxCountry::code($country);
        }

        return $bands[$this->bandFor($category, $hasAudioVisualComponent)->value]
            ?? $bands[TaxRateCategory::Standard->value];
    }

    /** Whether a country appears in the table at all — asked before opening a market, not on an invoice. */
    public function covers(string $country): bool
    {
        return isset($this->rates[strtoupper($country)]);
    }

    /** How old the table is, in whole days, against the moment asked about. */
    public function ageInDays(CarbonInterface $now): int
    {
        return (int) $this->validFrom->diffInDays($now, absolute: false);
    }

    /**
     * The band actually granted, after the audio-visual gate.
     *
     * Written as its own step because the gate is the whole point of the ticket this comes from: folding it
     * into the lookup would make "asked for reduced" and "got reduced" the same expression, and the one
     * thing that must be readable here is that they are not.
     */
    private function bandFor(TaxRateCategory $category, bool $hasAudioVisualComponent): TaxRateCategory
    {
        if ($category === TaxRateCategory::Reduced && $hasAudioVisualComponent) {
            return TaxRateCategory::Standard;
        }

        return $category;
    }
}
