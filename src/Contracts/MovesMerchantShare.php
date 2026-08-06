<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\MerchantAccountReference;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\TransferResult;

/**
 * Moving the merchant's share to them, as a call of its own.
 *
 * ## Why this is a separate interface and not a method on an existing one
 *
 * `PaymentRails` and `MarketplaceRails` are both implemented OUTSIDE this package — a consumer registers
 * its own driver through the public extension point. Adding a method to either is a fatal error in code
 * this package does not own and cannot fix. A new interface costs nothing to ignore: a driver either is a
 * `MovesMerchantShare` or it is not, and the type system answers the question before anything runs.
 *
 * ## Why the operation exists at all
 *
 * On a destination charge the provider creates the transfer as part of the payment: one call, and the
 * merchant's share is already on its way. On a separate transfer it does not. The platform takes the whole
 * payment, and moving the share is a SECOND call that somebody has to make — and if nobody does, the
 * merchant is simply never paid while every signal looks healthy.
 *
 * That was the state of this package until now, on the DEFAULT charge type, which is why the driver refused
 * the routing outright rather than serving half of it.
 *
 * ## Bound to the payment it came from
 *
 * The source charge is not optional and not decoration. A transfer that names it is funded by that specific
 * payment; one that does not is funded by whatever happens to be on the platform balance — which fails when
 * the balance is short, succeeds by accident when it is not, and in both cases loses the link between the
 * money a buyer paid and the money a merchant received. Reconciliation, refunds and reversals all need that
 * link, and it cannot be reconstructed afterwards.
 */
interface MovesMerchantShare
{
    /**
     * Move an amount to a merchant, funded by a specific payment.
     *
     * The idempotency key is the caller's, and it must be derived from stable local state rather than from
     * a freshly computed amount: a retry that recomputed a slightly different figure would produce a second
     * key and a second transfer, which is the failure this parameter exists to prevent.
     *
     * A driver that cannot serve this must THROW rather than return a result that says nothing moved —
     * silence here is a merchant who is never paid.
     */
    public function transferShare(
        MerchantAccountReference $destination,
        Money $amount,
        string $sourceCharge,
        ?string $idempotencyKey = null,
    ): TransferResult;
}
