<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Pushery\Billing\Enums\TaxRateCategory;

/**
 * One rate, for one country and band, over the span of time it actually applied — and where it came from.
 *
 * ## Why a rate is an interval and not a number
 *
 * A rate table keyed `country => rate` can hold exactly one answer per country: the current one. That is
 * enough right up to the moment a member state changes its rate, and then it is wrong in a way nothing can
 * see. Estonia went from 22 to 24 on 1 July 2025. Under a single-value table, a credit note written in 2027
 * for a supply made in 2025 carries the 2027 rate — and that is not a mistake somebody makes, it is what the
 * code does. The law binds the rate to the tax point (Art. 93 VAT Directive), not to the moment of lookup.
 *
 * ## Why the provenance travels with it
 *
 * A wrong rate is normal — rates change by law. What made the Estonian case survive a year was that nothing
 * could find it: the table was a constant with no source, no fetch date and no version. Carrying those means
 * the question "where did this number come from and when did we last check" has an answer that does not
 * require reading git history.
 *
 * `approvedBy` is deliberately nullable and deliberately present. A rate that arrived by import and was
 * never reviewed is a different thing from one a human signed off, and an operator is entitled to tell them
 * apart before a return is filed.
 */
final readonly class TaxRateInterval
{
    public function __construct(
        public string $country,
        public TaxRateCategory $category,
        public int $rateBps,
        /** The first day this rate applied. */
        public CarbonImmutable $validFrom,
        /** The first day it no longer applied, or null while it is the current one. */
        public ?CarbonImmutable $validTo,
        /** Where the figure came from — a named publisher, not "the internet". */
        public string $source,
        /** Which edition of that source, so two fetches of the same day are distinguishable. */
        public string $sourceVersion,
        /** When it was retrieved. */
        public CarbonImmutable $fetchedAt,
        /** Who accepted it, or null where it arrived by import and nobody has looked. */
        public ?string $approvedBy = null,
    ) {
        if ($rateBps < 0 || $rateBps > 10_000) {
            throw new InvalidArgumentException(
                "The rate for {$country} is {$rateBps} basis points, outside 0 to 10000."
            );
        }

        if ($validTo instanceof CarbonImmutable && ! $validTo->greaterThan($validFrom)) {
            throw new InvalidArgumentException(
                "The rate interval for {$country} ends on or before it begins."
            );
        }
    }

    /** Whether this interval covers a tax point. Open-ended intervals run forward without limit. */
    public function covers(CarbonImmutable $taxPoint): bool
    {
        if ($taxPoint->lessThan($this->validFrom)) {
            return false;
        }

        return ! $this->validTo instanceof CarbonImmutable || $taxPoint->lessThan($this->validTo);
    }

    /** Whether this interval overlaps another for the same country and band. */
    public function overlaps(self $other): bool
    {
        if ($this->country !== $other->country || $this->category !== $other->category) {
            return false;
        }

        $startsBeforeOtherEnds = ! $other->validTo instanceof CarbonImmutable
            || $this->validFrom->lessThan($other->validTo);

        $otherStartsBeforeThisEnds = ! $this->validTo instanceof CarbonImmutable
            || $other->validFrom->lessThan($this->validTo);

        return $startsBeforeOtherEnds && $otherStartsBeforeThisEnds;
    }
}
