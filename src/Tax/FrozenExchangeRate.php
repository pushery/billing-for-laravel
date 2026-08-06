<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Pushery\Billing\Enums\ExchangeRateBasis;

/**
 * The rate a booking was actually converted at, with everything needed to defend it.
 *
 * An exchange rate is **part of the booking**, not a runtime conversion. Computed at display time, nobody
 * can say years later what was booked — and the difference between the rate that was used and the rate a
 * later lookup returns is exactly what an audit finds.
 *
 * Five things travel together, and the last is the one people forget:
 *
 * - the amount in its original currency,
 * - the rate,
 * - the **date of the rate** — which is the date the publisher stated, never the day it was fetched,
 * - the source,
 * - **the rule under which that rate was the correct one**.
 *
 * "Which rate did you use" is the easy question. "Why was that the right rate" is the first one an audit
 * asks, and a frozen number without its rule leaves a reviewer choosing between three regimes that each
 * produce a defensible-looking answer.
 *
 * ## The measured trap this class exists to make impossible
 *
 * Fetched on a Saturday, the central bank's daily file returns **HTTP 200 carrying Friday's data**. No 404,
 * no error. The date is inside the document. Stamping the rate with the system clock therefore books a rate
 * for a day the bank never published — and that surfaces only when somebody holds the document against the
 * official series. So `on` is required and is documented as the publisher's date; there is no constructor
 * path that defaults it to today.
 *
 * ## Which way it points, and why it is never turned around
 *
 * `fromCurrency` → `toCurrency` reads as the publisher states it: **how many units of `toCurrency` one unit
 * of `fromCurrency` buys**. The central bank publishes "1 EUR = 11.0550 SEK", so that is a rate FROM EUR TO
 * SEK, and converting an amount in EUR into SEK multiplies by it.
 *
 * Nothing here inverts a rate to answer the other direction, and that is a rule rather than an omission. An
 * inverted rate is a figure the publisher never issued: 1/11.0550 is 0.09045680... which has to be rounded
 * to be stored, and the rounded inverse multiplied back does not return the amount you started from. On a
 * tax document that discrepancy is a number nobody can reconcile against the official series — which is the
 * same failure this whole seam refuses when it declines to substitute a nearest-day rate.
 *
 * So a caller asking for a direction nobody published gets a refusal, exactly as it would for a day nobody
 * published. Whoever needs the other direction converts deliberately, at a scale they have chosen, and owns
 * the rounding.
 *
 * This was undefined until 2026-07-27 — `rateScaled` had no consumer in the package at all, so no code was
 * wrong, but the first importer would have made whichever direction it happened to write permanent.
 */
final readonly class FrozenExchangeRate
{
    public function __construct(
        /** The currency an amount is IN. One unit of this buys `rateScaled` units of `toCurrency`. */
        public string $fromCurrency,
        /** The currency the amount is being expressed in. */
        public string $toCurrency,
        /** The rate, scaled by 1e8 so it is an integer like every other figure on the money path. */
        public int $rateScaled,
        /** The date the PUBLISHER stated for this rate — never the day it was retrieved. */
        public CarbonImmutable $on,
        public string $source,
        public ExchangeRateBasis $basis,
    ) {
        if ($rateScaled <= 0) {
            throw new InvalidArgumentException('An exchange rate must be positive.');
        }

        if ($fromCurrency === $toCurrency) {
            throw new InvalidArgumentException(
                'A conversion between one currency and itself is not a conversion. Recording one would put a '
                .'rate on a booking that was never converted.'
            );
        }
    }

    /** The scale rates are stored at — eight decimal places, as an integer. */
    public const int SCALE = 100_000_000;

    /**
     * Whether two rates from different channels are the same number.
     *
     * Compared numerically and never as text. The two channels of the same publisher format identical values
     * differently — `11.0550` against `11.055`, `143.00` against `143` — so a string or hash comparison
     * raises a false alarm **between two genuine copies of the same official figure**. Measured, not assumed.
     *
     * NO PRODUCTION CALLER YET, and that is a state rather than an oversight. The consumer it was written for
     * is the cross-check performed when a SECOND official source is imported beside the central bank's
     * series — comparing the overlapping currencies between two publishers is the moment a formatting
     * difference would otherwise read as a disagreement about the rate. That importer does not exist: which
     * publisher supplies the currencies outside the central bank's series is an open question, and picking
     * one would be choosing an authority rather than implementing one.
     *
     * Kept rather than deleted because the reasoning above is the expensive part and it was arrived at by
     * measurement. Deleting it would mean rediscovering that two channels of one publisher disagree on
     * trailing zeros, most likely from a false alarm in production.
     */
    public static function sameRate(string $a, string $b): bool
    {
        return abs((float) $a - (float) $b) < 0.000_000_005;
    }

    /** Parse a published decimal rate into the integer scale. */
    public static function scale(string $decimal): int
    {
        return (int) round((float) $decimal * self::SCALE);
    }
}
