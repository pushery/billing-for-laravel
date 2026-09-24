<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A prorated cancellation found nothing it could settle safely, so nothing was done.
 *
 * Every case is found while reading, before the subscription ends and before money moves. A cancellation that had
 * ended the subscription first and only then found it could not settle would leave the owner with an end and no
 * answer about their money.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping a fragment
 * measures where the line was wrapped, not what a test asserts.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class CancellationUnavailable extends RuntimeException
{
    /** The owner holds no live subscription in the scope, so there is nothing to cancel. */
    public static function noLiveSubscription(string $merchantUid): self
    {
        return new self(
            "There is no live subscription in the {$merchantUid} scope to cancel. A subscription that has already "
            .'ended has nothing left to end, and a payment it left behind is refunded through BillingAdmin::refund().'
        );
    }

    /** The period's payment is still being collected, and settling now could miss money that is yet to arrive. */
    public static function paymentInFlight(Throwable $previous): self
    {
        return new self(
            'The payment for the current period is still being collected. Canceling to a date now would end the '
            .'subscription while that money can still arrive, with nothing to refund it against. Cancel once the '
            .'payment has succeeded or failed.',
            previous: $previous,
        );
    }
}
