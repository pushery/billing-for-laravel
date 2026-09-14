<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A subscription withdrawal found nothing it could settle safely, so nothing was done.
 *
 * Every case is found while reading, before the subscription ends and before money moves. A withdrawal that had
 * ended the subscription first and only then found it could not settle would leave the buyer without a contract
 * and without an answer about their money.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping a fragment
 * measures where the line was wrapped, not what a test asserts.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class SubscriptionWithdrawalUnavailable extends RuntimeException
{
    /** The owner holds no live subscription in the scope, so there is nothing to withdraw from. */
    public static function noLiveSubscription(string $merchantUid): self
    {
        return new self(
            "There is no live subscription in the {$merchantUid} scope to withdraw from. A subscription that has "
            .'already ended has nothing left to end, and a payment it left behind is refunded through '
            .'BillingAdmin::refund().'
        );
    }

    /** The tier's taxonomy leaves the withdrawal right open, so neither the window nor the part to keep can be read. */
    public static function unclassifiedTier(string $tierKey): self
    {
        return new self(
            "The taxonomy fixes no withdrawal type for tier '{$tierKey}', so neither the window nor the part to keep "
            .'can be read. Give the tier an archetype whose withdrawal is fixed before settling withdrawals on it.'
        );
    }

    /** The period's payment is still being collected, and settling now could miss money that is yet to arrive. */
    public static function paymentInFlight(string $reference): self
    {
        return new self(
            "The payment for the current period ({$reference}) is still being collected. Settling the withdrawal now "
            .'would end the subscription while that money can still arrive, with nothing to refund it against. '
            .'Settle it once the payment has succeeded or failed.'
        );
    }
}
