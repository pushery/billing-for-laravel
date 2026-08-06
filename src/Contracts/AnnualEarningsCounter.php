<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\Money;

/**
 * How much a party earned in one calendar year — the raw, jurisdiction-NEUTRAL basis a threshold monitor
 * evaluates against a limit.
 *
 * It knows nothing of any tax regime: no limit, no status, no country. It answers one factual question —
 * "what did this party actually receive in year Y, in currency C" — on the CASH basis (counted when the
 * money arrived, not when the sale happened) and net of anything reversed in that same year. A jurisdiction
 * profile takes this number and decides what a limit break means; the number itself carries no meaning.
 *
 * The basis is the payout net (what the party received), never the buyer's gross and never the pre-fee
 * amount — those are different counters for different purposes, and confusing them is the whole error class
 * this seam exists to prevent. A rebuild from the booked transactions must reproduce the running figure, so
 * the count is a projection over the record rather than a mutable tally that could drift from it.
 */
interface AnnualEarningsCounter
{
    /**
     * The party's payout-net earnings that ARRIVED in the given calendar year and currency, less anything
     * reversed (refund, clawed-back dispute) that COMPLETED in that same year — the cash basis in both
     * directions.
     */
    public function earnedIn(Model $party, string $currency, int $year): Money;
}
