<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Where a submitted invoice stands in the fallback lane's review — and, by extension, whether its payout may
 * be released and whether it may be drawn into input tax.
 *
 * Only {@see ReviewState::Passed} releases a payout; everything else holds it. A foreign format that cannot
 * be parsed automatically is not a failure — a creator in another member state rightly bills in their own
 * country's format (Art. 219a Abs. 2 MwStSystRL) — so it goes to a human review rather than a rejection.
 */
enum ReviewState: string
{
    /** Received, not yet reconciled — payout held. */
    case Pending = 'pending';

    /** Reconciled against the transaction data and cleared — the one state that releases a payout. */
    case Passed = 'passed';

    /** Reconciled and rejected (amounts do not match, a mandatory field is missing) — payout held. */
    case Failed = 'failed';

    /** A format the automatic ingest does not read (a foreign e-invoice) — routed to a human, payout held. */
    case ManualReview = 'manual_review';

    /** Whether this state clears the invoice for payout. Only a passed review does. */
    public function releasesPayout(): bool
    {
        return $this === self::Passed;
    }
}
