<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * A withdrawal was declared after the buyer's window closed, so it cannot be booked as a statutory one.
 *
 * ## What this refuses, and what it deliberately does not
 *
 * It refuses the CLASSIFICATION, never the money. A platform may refund out of goodwill whenever it
 * likes, and that path is untouched — `BillingAdmin::refund()` with the default kind does exactly that.
 * What must not happen is the two being recorded as the same event: `RefundKind::StatutoryWithdrawal` is
 * the statement "the buyer exercised a RIGHT", and after the window that statement is false.
 *
 * The money is identical and the events are not. Telling them apart is the whole reason the kind exists,
 * and a decision the platform made and could have made differently belongs on the other side of that line.
 */
final class WithdrawalWindowClosed extends RuntimeException
{
    public function __construct(
        public readonly string $chargeReference,
        public readonly CarbonInterface $windowEndedAt,
    ) {
        parent::__construct(
            "The withdrawal window for {$chargeReference} closed on {$windowEndedAt->toDateTimeString()}, so this "
            .'cannot be recorded as a statutory withdrawal — after the window a refund is the platform\'s '
            .'decision rather than the buyer\'s right, and the two are different events in the books. Refund it '
            .'as goodwill through BillingAdmin::refund() if you mean to give the money back anyway.'
        );
    }
}
