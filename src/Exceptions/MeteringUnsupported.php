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
