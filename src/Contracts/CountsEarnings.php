<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\CountingPeriod;
use Pushery\Billing\ValueObjects\Money;

/**
 * What a party earned over an arbitrary window — the same factual question {@see AnnualEarningsCounter}
 * answers, with the window as an argument instead of a calendar year.
 *
 * ## Why the window had to come out
 *
 * This package owes three counters over the same transactions, and they differ in exactly two settings: the
 * WINDOW and the BASIS. The threshold monitor counts payout-net over a calendar year; a reporting counter
 * counts a party's gross inflow over a calendar QUARTER; a nexus counter counts the buyer's gross by region.
 * A seam that takes an `int $year` can serve the first and nothing else — so the others would each bring
 * their own counting, and two tallies over one set of transactions drift apart at the first refund.
 *
 * That drift is not hypothetical arithmetic: a refund lands in one window and not another, so a counter that
 * rebuilds and a counter that accumulates disagree the moment money goes back. Sharing the seam is what
 * makes the three answers reconcilable to the same rows.
 *
 * ## The basis is still the implementation's, and deliberately so
 *
 * There is no basis argument here. Naming an enum of bases before a second implementation exists would put
 * two cases in it that nothing selects — the shape this package keeps finding and removing. The basis stays
 * documented on each implementation until there is a second one to distinguish, and that is the slice which
 * introduces it.
 *
 * ## Both contracts, on purpose
 *
 * {@see AnnualEarningsCounter} stays, unchanged and still bound. It is implemented outside this package — a
 * consumer with its own earnings source binds its own — so replacing it would be a fatal error in code we do
 * not own. An implementation that can serve arbitrary windows announces it by implementing this as well.
 */
interface CountsEarnings
{
    /**
     * The party's earnings that ARRIVED within the window, in that currency, less anything reversed that
     * COMPLETED within the same window — the cash basis in both directions, exactly as the annual counter
     * defines it.
     */
    public function countedIn(Model $party, string $currency, CountingPeriod $period): Money;
}
