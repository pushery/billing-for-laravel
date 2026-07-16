<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when a coupon cannot be redeemed: it is inactive, expired, has reached its global
 * max-redemptions cap, or the owner has already redeemed it. Each reason is a recoverable runtime
 * condition (show the customer why the code was rejected), not a programming error — so the caller can
 * catch this and surface the message, while a redemption that WOULD over-grant the discount never happens.
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
}
