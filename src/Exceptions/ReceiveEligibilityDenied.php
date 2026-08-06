<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * Money was about to be destined to a merchant the receiving gate denies.
 *
 * Thrown BEFORE any provider call, and that ordering is the whole value. Once a routed charge is in
 * flight, a merchant who cannot receive does not produce a clean rejection: the money settles to whoever
 * the provider can reach — usually the platform — while the local records say it was split. Unwinding that
 * is manual, per transaction, and only starts once somebody reconciles.
 *
 * It is deliberately distinct from {@see EligibilityDenied}, which refuses the BUYER. The two are refused
 * for unrelated reasons and fixed by different people, and one exception for both would send whoever reads
 * it to the wrong person.
 */
final class ReceiveEligibilityDenied extends RuntimeException
{
    public static function forMerchant(): self
    {
        return new self(
            'The merchant is not eligible to receive money; the routed payment was refused before it '.
            'reached the provider.'
        );
    }
}
