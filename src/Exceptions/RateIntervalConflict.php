<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\Tax\TaxRateInterval;
use RuntimeException;

/**
 * Two rate intervals claim the same country, band and moment.
 *
 * Refused rather than resolved, because there is no resolution that is not a guess. The table is append-only
 * for one reason: overwriting a rate makes it impossible to reconstruct afterwards what an invoice said —
 * not for the auditor, not for the operator, not for a court. A conflict means the incoming data disagrees
 * with what is already held, and that disagreement is information; silently keeping one of the two destroys it.
 */
final class RateIntervalConflict extends RuntimeException
{
    public static function between(TaxRateInterval $existing, TaxRateInterval $incoming): self
    {
        $from = $existing->validFrom->toDateString();
        $to = $existing->validTo?->toDateString() ?? 'open';
        $incomingFrom = $incoming->validFrom->toDateString();

        return new self(
            "A {$existing->category->value} rate for {$existing->country} already covers {$from} to {$to}; "
            ."the incoming interval starts {$incomingFrom} and overlaps it. Rates are append-only — close the "
            .'existing interval and append the new one rather than writing over what a document may cite.'
        );
    }
}
