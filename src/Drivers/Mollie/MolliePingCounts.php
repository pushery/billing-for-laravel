<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * How often a classic ping may name one Mollie payment before Mollie is no longer asked about it.
 *
 * The classic ping is unsigned, and the mapper believes it only after asking Mollie about the payment it names,
 * with the account's key. Mollie limits the reads of an organization. Anybody who knows a payment, a payer who
 * knows their own for instance, could name it as often as the route lets a sender through, and each time Mollie
 * would be asked and the payment worked again. So a payment may be named {@see self::REPEATS} times in
 * {@see self::SECONDS} seconds, and a payment Mollie answered it does not know is not asked about again in that
 * time, before the verifier refuses the pings that name it. Mollie pings a payment once for each change of its
 * status and repeats one it could not deliver at growing intervals, far below that count, and it delivers a
 * refused ping again later.
 *
 * The counts are kept per payment and never per sender, because the package reads no network address. How much
 * one sender may send in all, and so how many payments it can make up, is what a throttle per address in
 * `billing.webhook_middleware` limits. Each count is kept under a key that does not name the payment plainly: a
 * count is a cache entry, and an error tracker records the key of every entry it sees.
 *
 * @internal
 */
final class MolliePingCounts
{
    /** How often a classic ping may name one payment in {@see self::SECONDS} seconds. */
    public const int REPEATS = 10;

    /** The length of both windows, in seconds. */
    public const int SECONDS = 3600;

    /** Whether $payment has been named as often as it may be, or Mollie answered that it does not know it. */
    public static function spent(string $payment): bool
    {
        return RateLimiter::tooManyAttempts(self::repeatKey($payment), self::REPEATS)
            || RateLimiter::tooManyAttempts(self::unknownKey($payment), 1);
    }

    /** Count one classic ping that names $payment. */
    public static function named(string $payment): void
    {
        RateLimiter::hit(self::repeatKey($payment), self::SECONDS);
    }

    /**
     * Remember that Mollie does not know $payment, where $request is a classic ping. An event names its entity in a
     * body Mollie signs, so it is not remembered.
     */
    public static function unknown(Request $request, string $payment): void
    {
        if ($request->input('type') === null && $request->input('entityId') === null) {
            RateLimiter::hit(self::unknownKey($payment), self::SECONDS);
        }
    }

    private static function repeatKey(string $payment): string
    {
        return 'billing:mollie-ping:'.hash('sha256', $payment);
    }

    private static function unknownKey(string $payment): string
    {
        return 'billing:mollie-unknown-payment:'.hash('sha256', $payment);
    }
}
