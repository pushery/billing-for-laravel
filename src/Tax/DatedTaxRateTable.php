<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Pushery\Billing\Enums\TaxRateCategory;
use Pushery\Billing\Exceptions\RateIntervalConflict;
use Pushery\Billing\Exceptions\UnknownTaxRateAt;

/**
 * Rates as dated intervals, queryable only with the tax point they are meant to answer for.
 *
 * ## The one design decision worth stating
 *
 * **There is no method here that can be called without a date.** Not a method with a default of "today" — a
 * default would be the same trap with a better conscience, because the call site would look correct and the
 * answer would still be pinned to when the code ran rather than to when the supply happened. A caller that
 * does not know its tax point has a problem this class cannot solve for it, and hiding that behind a default
 * turns a visible gap into an invisible wrong number.
 *
 * ## Append-only, and why that is not a style preference
 *
 * `add()` refuses an interval that overlaps one already present. Overwriting is the operation that ends a
 * company: afterwards nobody can reconstruct what an invoice said — not the auditor, not the operator, not
 * a court. A rate change appends a new interval and closes the previous one; it never edits in place.
 *
 * ## A gap is not the oldest rate
 *
 * Asked about a tax point before the first interval, this refuses. Returning the oldest known rate would be
 * an invention with a date on it, and inventions with dates on them are indistinguishable from facts. "We do
 * not know" is the only honest answer, and it is one a caller can act on.
 */
final class DatedTaxRateTable
{
    /** @var list<TaxRateInterval> */
    private array $intervals = [];

    /** @param  list<TaxRateInterval>  $intervals */
    public function __construct(array $intervals = [])
    {
        foreach ($intervals as $interval) {
            $this->add($interval);
        }
    }

    /**
     * Append an interval.
     *
     * @throws RateIntervalConflict when it overlaps one already present
     */
    public function add(TaxRateInterval $interval): self
    {
        foreach ($this->intervals as $existing) {
            if ($existing->overlaps($interval)) {
                throw RateIntervalConflict::between($existing, $interval);
            }
        }

        $this->intervals[] = $interval;

        return $this;
    }

    /**
     * The rate that applied at a tax point, in basis points.
     *
     * @throws UnknownTaxRateAt when no interval covers that moment
     */
    public function rateAt(string $country, TaxRateCategory $category, CarbonImmutable $taxPoint): int
    {
        return $this->intervalAt($country, $category, $taxPoint)->rateBps;
    }

    /**
     * The whole interval that applied, provenance included.
     *
     * Offered beside `rateAt()` because a document freezing a rate should be able to freeze where it came
     * from in the same breath — asking twice is how the two drift apart.
     *
     * @throws UnknownTaxRateAt when no interval covers that moment
     */
    public function intervalAt(string $country, TaxRateCategory $category, CarbonImmutable $taxPoint): TaxRateInterval
    {
        $code = strtoupper($country);

        foreach ($this->intervals as $interval) {
            if ($interval->country === $code && $interval->category === $category && $interval->covers($taxPoint)) {
                return $interval;
            }
        }

        // Falling back to the standard band mirrors what the undated table did: a country that has no reduced
        // band for this kind of supply taxes it at its standard rate. The fallback is between BANDS of the
        // same moment, never between moments — a missing date is still a refusal.
        if ($category !== TaxRateCategory::Standard) {
            return $this->intervalAt($country, TaxRateCategory::Standard, $taxPoint);
        }

        throw UnknownTaxRateAt::forCountry($code, $taxPoint);
    }

    /**
     * Whether the table carries ANY interval for a country, whatever the moment.
     *
     * Date-blind on purpose, and it is the seam that keeps a partial history from becoming a refusal engine.
     * An operator who configures intervals for one member state has said nothing about the others, so a
     * country absent here must fall through to the table that has always priced it. A country PRESENT here
     * is one the operator has taken responsibility for, and a tax point landing in a gap of it is then a
     * refusal rather than a fallback — which is the whole point of `intervalAt()`.
     */
    public function knowsCountry(string $country): bool
    {
        $code = strtoupper($country);

        return array_any($this->intervals, fn (TaxRateInterval $interval): bool => $interval->country === $code);
    }

    /** Whether any interval could answer for this country and moment — asked before opening a market. */
    public function covers(string $country, CarbonImmutable $taxPoint): bool
    {
        $code = strtoupper($country);

        return array_any($this->intervals, fn (TaxRateInterval $interval): bool => $interval->country === $code && $interval->covers($taxPoint));
    }

    /** @return list<TaxRateInterval> */
    public function all(): array
    {
        return $this->intervals;
    }
}
