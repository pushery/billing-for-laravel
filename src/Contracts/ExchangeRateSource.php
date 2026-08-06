<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Carbon\CarbonImmutable;
use Pushery\Billing\Enums\ExchangeRateBasis;
use Pushery\Billing\Exceptions\ExchangeRateUnavailable;
use Pushery\Billing\Tax\FrozenExchangeRate;

/**
 * Where a conversion rate comes from — and it never comes back as a number.
 *
 * ## Why a contract rather than a lookup
 *
 * Seven EU member states that are not geo-blocked settle in their own currency, and OSS reaches all of
 * them. So a package that books money in one currency and reports tax in another needs a rate — and the
 * moment it has one, the interesting question is not the number but where it came from and which day it
 * belongs to. A float answers neither, and a float is what every convenient API returns.
 *
 * The answer is therefore a {@see FrozenExchangeRate}: the rate as scaled integer, the date the PUBLISHER
 * stated, the source, and the rule that made it the correct one. That object already refuses the two ways
 * this goes wrong quietly — a non-positive rate, and a "conversion" between a currency and itself.
 *
 * ## The basis is an argument, deliberately
 *
 * Which rule applies is jurisdiction knowledge, and the rules genuinely contradict each other: German
 * domestic turnover takes the ministry's monthly average, the EU option takes the central bank's rate at
 * the tax point, and OSS takes the central bank's rate at period end while expressly excluding monthly
 * averages. On the same turnover.
 *
 * So the caller states which rule it is asking under, and this contract answers under that rule. A source
 * that picked the rule itself would be making a jurisdictional decision inside a neutral seam — and would
 * have to pick wrongly for somebody, since two of the three rules disagree by law.
 *
 * ## Asked in the published direction, never the inverse
 *
 * `rateFor('EUR', 'SEK', …)` asks how many SEK one EUR buys, because that is how the central bank states
 * it. The opposite direction is a **refusal**, not a division: 1/11.0550 is 0.09045680… which has to be
 * rounded to be stored, and the rounded inverse multiplied back does not return the amount you started
 * from. That discrepancy lands on a tax document as a figure nobody can hold against the official series —
 * the same failure this seam already refuses when it declines a nearest-day rate.
 *
 * Whoever needs the other direction converts deliberately, at a scale they chose, and owns the rounding.
 *
 * ## No live fetch, and no silent zero
 *
 * An implementation reads reference data it already holds. It does not call a bank on the critical path of
 * a sale: the rate for a past date does not change, and a payment must not wait on somebody else's uptime.
 *
 * A rate that is not held is a REFUSAL, never a zero and never today's rate as a stand-in. Both of those
 * produce a document that looks defensible and states a figure nobody published. Implementations throw
 * {@see ExchangeRateUnavailable}.
 *
 * There is a specific trap behind the date argument, measured rather than imagined: fetched on a Saturday,
 * a central bank's daily file answers HTTP 200 carrying FRIDAY's data. No error, no 404 — the real date is
 * inside the document. An implementation therefore stamps the publisher's date, never the clock, and a
 * rule that has no rate on a given day (a weekend, a holiday) resolves forward to the next publication day
 * rather than inventing one.
 */
interface ExchangeRateSource
{
    /**
     * The published rate for converting one currency into another on a given day, under a stated rule.
     *
     * @param  string  $from  ISO-4217 code the amount is in — one unit of it buys the returned rate's worth
     *                        of `$to`. Asked in the direction the publisher states, never the inverse
     * @param  string  $to  ISO-4217 code the amount is being expressed in
     * @param  CarbonImmutable  $on  the day the conversion belongs to — a tax point, a period end, a month
     * @param  ExchangeRateBasis  $basis  which rule the caller is asking under; see the enum for why it is not a default
     *
     * @throws ExchangeRateUnavailable when no rate is held for that combination
     */
    public function rateFor(string $from, string $to, CarbonImmutable $on, ExchangeRateBasis $basis): FrozenExchangeRate;
}
