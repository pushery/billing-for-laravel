<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;
use Throwable;

/**
 * A cancellation found nothing it could settle safely, so nothing was done.
 *
 * Every case is found before the subscription ends, before money moves and before a document number is drawn. A cancellation that had ended the subscription first and only then found it could not settle would leave
 * the owner with an end and no answer about their money.
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

    /**
     * The prepaid term paid by this charge was canceled already, and its refund was asked for then.
     *
     * @param  CarbonInterface  $canceledAt  when the first cancellation was stamped on the charge
     */
    public static function termAlreadyCanceled(string $provider, string $chargeReference, CarbonInterface $canceledAt): self
    {
        return new self(sprintf(
            'The prepaid term paid by %s charge %s was canceled already, at %s, and its refund was asked for then. '
            .'A term is canceled once; a second cancellation would refund the unused part a second time. The outcome '
            .'of that refund is in its admin.refund audit record, and a refund still owed goes through BillingAdmin::refund().',
            $provider,
            $chargeReference,
            $canceledAt->toIso8601String(),
        ));
    }
}
