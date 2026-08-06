<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Carbon\CarbonInterface;

/**
 * A jurisdiction profile that knows its own limit for small cross-border consumer sales.
 *
 * A marker a profile opts into, like {@see SuppliesTaxRates}: widening the base contract would be a fatal
 * error in a consumer's own profile class.
 *
 * The limit belongs here and nowhere else. Written into a core class it would be a foreign statute's number
 * living inside machinery that a consumer on another continent also runs — and it would go stale silently,
 * because a limit that is wrong looks exactly like a limit that is right until a return is filed against it.
 * A profile that does not implement this has no limit to watch, and the monitor stays out of the way.
 */
interface SuppliesDistanceSaleThreshold
{
    /**
     * The yearly limit, in minor units of the reporting currency, below which such sales stay taxed at the
     * seller. Zero or less means the jurisdiction has no such limit.
     */
    public function distanceSaleThresholdMinor(): int;

    /** The day that limit was known to be correct, so its age can be reported rather than assumed. */
    public function distanceSaleThresholdValidFrom(): CarbonInterface;
}
