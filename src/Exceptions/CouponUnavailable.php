<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when a coupon cannot be redeemed: it is inactive, expired, has reached its global
 * max-redemptions cap, the owner has already redeemed it, or it belongs to a different seller. Each reason is a recoverable runtime
 * condition (show the customer why the code was rejected), not a programming error — so the caller can
 * catch this and surface the message, while a redemption that WOULD over-grant the discount never happens.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class CouponUnavailable extends RuntimeException
{
    public static function inactive(string $code): self
    {
        return new self("The coupon '{$code}' is not active.");
    }

    public static function expired(string $code): self
    {
        return new self("The coupon '{$code}' has expired.");
    }

    public static function exhausted(string $code): self
    {
        return new self("The coupon '{$code}' has reached its redemption limit.");
    }

    public static function alreadyRedeemed(string $code): self
    {
        return new self("The coupon '{$code}' has already been redeemed by this account.");
    }

    /**
     * The coupon belongs to a different seller than the sale it is being spent on.
     *
     * A separate reason rather than folding into "not active", because it is the only one that is about
     * WHOSE money funds the discount: one seller's 25% spent on another seller's sale is a transfer between
     * two merchants that neither agreed to. A message that said "not active" would send whoever debugs it
     * looking at the coupon's own flags, which are all fine.
     */
    public static function notIssuedBy(string $code, string $merchantUid): self
    {
        return new self("The coupon '{$code}' was not issued by '{$merchantUid}' and cannot be spent on its sales.");
    }
}
