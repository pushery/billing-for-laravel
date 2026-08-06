<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\TransferReversal;

/**
 * Taking a merchant's share back, as a call of its own.
 *
 * ## Why the separate-transfer lane needs this and the destination lane does not
 *
 * On a destination charge the provider created the transfer as part of the payment, so refunding the payment
 * can unwind both together — one call, one idempotency key, nothing to compute. On a separate transfer the
 * money moved in a SECOND call, and refunding the payment does not touch it. Somebody has to reverse it
 * explicitly, with an amount somebody has to work out.
 *
 * That lane is the shipped default, and until now it had no way to do this at all: {@see MovesMerchantShare}
 * declares `transferShare()` and nothing else. So a marketplace on the defaults could pay a merchant and had
 * no path to claw any of it back.
 *
 * ## Why a sibling contract rather than a second method on MovesMerchantShare
 *
 * That interface is implemented outside this package — a consumer registers its own driver through the public
 * extension point. A method added there is a fatal error in code this package does not own and cannot fix. A
 * driver either can reverse a share or it cannot, and the type system answers that before anything runs. The
 * same reasoning already governs {@see RoutesMoney} and {@see SuppliesProductArchetypes}.
 *
 * ## The amount is the caller's, and that is the point
 *
 * The provider's own proportional reversal is the wrong figure whenever the platform fee has a fixed
 * component: on a half refund it returns half of what was paid out, while what is actually owed back is the
 * difference against a RECOMPUTED remaining payout. That difference is small, permanent, and both numbers
 * look reasonable. So this takes an amount rather than a percentage, and the caller computes it.
 */
interface ReversesMerchantShare
{
    /**
     * Take back part or all of a transfer.
     *
     * The idempotency key is the caller's and must come from stable local state rather than a freshly
     * computed amount — a retry that recomputed a slightly different figure would produce a second key and a
     * second reversal, which is the failure the parameter exists to prevent. It is the same rule the
     * outbound side follows, and it matters more here: a doubled reversal takes money a merchant earned.
     *
     * A driver that cannot serve this must THROW rather than return a result that says nothing moved.
     * Silence here is a clawback everybody believes happened.
     */
    public function reverseShare(
        string $transferReference,
        Money $amount,
        ?string $idempotencyKey = null,
    ): TransferReversal;
}
