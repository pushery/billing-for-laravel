<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Enums\TaxRateCategory;

/**
 * The rate a line states, read back from the base and the tax a provider charged on it.
 *
 * A provider that determines the tax itself reports each line's base and the tax on it, and not the rate: the rate
 * object is not expanded in a webhook. The quotient of the two is not a rate. Both figures are rounded to the minor
 * unit, so 1.60 on 8.39 is 19.07 % and 0.16 on 0.83 is 19.28 %, and a document stating either states a rate no
 * country levies, where an invoice has to name the rate applied (Art. 226 No. 9 of the VAT Directive).
 *
 * So the quotient is matched against the rates the tables know, those of the countries the supply can be taxed in
 * first, the buyer's and the seller's, then every other rate the tables carry. A rate fits where its own tax on the
 * base lands within one minor unit of the tax charged, which a price set gross makes the ordinary case, and of the
 * rates that fit, the one nearest the quotient is taken. Where none fits, the line keeps its quotient to one
 * decimal, which is the rate a provider applied from a table this package does not carry.
 */
final readonly class ChargedTaxRate
{
    /**
     * @param  list<list<int>>  $tiers  candidate rates in basis points, the likeliest tier first
     */
    public function __construct(private array $tiers) {}

    /**
     * The candidates for a supply between these countries: their own rates first, then every rate the tables hold.
     *
     * @param  list<string>  $countries  the buyer's and the seller's, as ISO codes
     */
    public static function between(array $countries, ShippedTaxRates $shipped, ?TaxRateMatrix $matrix = null): self
    {
        $own = [];

        foreach ($countries as $country) {
            $code = strtoupper($country);

            if (isset($shipped->bps[$code])) {
                $own[] = $shipped->bps[$code];
            }

            if ($matrix instanceof TaxRateMatrix && $matrix->covers($code)) {
                $own[] = $matrix->rateFor($code);
                $own[] = $matrix->rateFor($code, TaxRateCategory::Reduced);
            }
        }

        return new self([$own, [...array_values($shipped->bps), ...($matrix?->rates() ?? [])]]);
    }

    /**
     * The rate, as a percentage, that a tax of `$tax` on `$base` states.
     *
     * Zero where nothing was charged: a line without tax states no rate that a table could supply. A credit line
     * carries both figures negative and states the same rate as the sale it reverses.
     */
    public function of(int $base, int $tax): float
    {
        if ($tax === 0 || $base === 0) {
            return 0.0;
        }

        $base = abs($base);
        $tax = abs($tax);
        $quotient = $tax / $base * 10_000;

        foreach ($this->tiers as $tier) {
            $nearest = null;

            foreach ($tier as $bps) {
                if ($bps <= 0 || abs($this->taxOn($base, $bps) - $tax) > 1) {
                    continue;
                }

                if ($nearest === null || abs($bps - $quotient) < abs($nearest - $quotient)) {
                    $nearest = $bps;
                }
            }

            if ($nearest !== null) {
                return $nearest / 100;
            }
        }

        return round($tax / $base * 100, 1);
    }

    /** The tax on a base at a rate in basis points, rounded half up as a document rounds it. */
    private function taxOn(int $base, int $bps): int
    {
        return intdiv(2 * $base * $bps + 10_000, 20_000);
    }
}
