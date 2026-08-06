<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * No rate interval covers this country at this moment.
 *
 * Thrown rather than answered with the oldest known rate. That fallback is tempting because it always
 * produces a number, and that is exactly what makes it dangerous: the number carries a date it was never
 * valid for, and an invention with a date on it is indistinguishable from a fact. "We do not know" is the
 * only honest answer for a moment the table has no data about, and it is one a caller can act on.
 */
final class UnknownTaxRateAt extends RuntimeException
{
    public static function forCountry(string $country, CarbonImmutable $taxPoint): self
    {
        return new self(
            "No tax rate is known for {$country} at {$taxPoint->toDateString()}. The table holds dated "
            .'intervals and this moment falls outside every one of them — supply the interval that applied '
            .'rather than accepting a rate from a period this supply did not happen in.'
        );
    }
}
