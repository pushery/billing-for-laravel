<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonInterface;

/**
 * The single transaction that carried a creator's cumulative year over a small-business limit — its identity
 * and its moment, not merely the fact that a limit was reached.
 *
 * The distinction is load-bearing (§ 19 Abs. 1 UStG): the exemption falls away FROM the transaction that
 * breaks the limit, and that transaction is itself taxable, while everything earlier in the year stays
 * exempt. A monitor that reported only "over the limit" could not tell the document chain where the line is.
 *
 * The cumulative here is the running sum of SETTLED payout net at the breaking charge — gross of reversals,
 * because a later refund does not un-break a break that already happened; it is a separate correction.
 */
final readonly class SmallBusinessThresholdBreach
{
    public function __construct(
        public string $chargeReference,
        public CarbonInterface $brokenAt,
        public int $limitMinor,
        public int $cumulativeMinor,
    ) {}
}
