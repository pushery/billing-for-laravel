<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * No published rate is held for the currency, day and rule that were asked for.
 *
 * Refused rather than substituted, and the two tempting substitutions are both worse than a failure.
 *
 * A zero turns a conversion into nothing: the converted amount becomes 0, which sails through every check
 * that asks whether a figure is present and lands on a document as a real-looking total. The nearest
 * neighbor — today's rate, or the last one held — is worse still, because it produces a number that is
 * plausible, off by whatever the currency moved, and indistinguishable from a correct one until somebody
 * holds the document against the official series.
 *
 * Neither failure announces itself. A refusal does.
 */
final class ExchangeRateUnavailable extends RuntimeException
{
    public static function forConversion(string $from, string $to, string $on, string $basis): self
    {
        return new self(
            "No published {$basis} rate is held for {$from} to {$to} on {$on}. The conversion is refused "
            .'rather than estimated: a zero would make the converted amount vanish into a plausible-looking '
            .'total, and the nearest available rate would state a figure the publisher never issued for '
            .'that day. Import the rate for that period, or ask under the rule whose rates you hold.'
        );
    }

    /** Nothing was bound to answer at all — the seam is open and no jurisdiction filled it. */
    public static function noSourceConfigured(): self
    {
        return new self(
            'No exchange-rate source is configured, so no conversion can be made. This package ships no '
            .'rates: which rates are correct is jurisdiction knowledge, and the rules contradict each other '
            .'across jurisdictions. Bind an ExchangeRateSource, or keep the install single-currency — a '
            .'single-currency install never converts and never reaches this.'
        );
    }
}
