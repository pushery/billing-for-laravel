<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A tier bills for usage on a driver that cannot report usage.
 *
 * This refuses to boot rather than degrade, because the degraded behavior is the worst one available:
 * the app would happily count every unit its customers use, report none of it, and invoice them for the
 * base fee alone. Nothing would look broken until the month's revenue came in short.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class MeteringUnsupported extends RuntimeException
{
    public static function forDriver(string $driver, string $meterKey): self
    {
        return new self(
            "Tier metering is configured (meter '{$meterKey}'), but the active billing driver ".
            "'{$driver}' cannot report usage. Metered usage would be counted and never billed."
        );
    }
}
