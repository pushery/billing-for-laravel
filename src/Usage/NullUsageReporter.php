<?php

declare(strict_types=1);

namespace Pushery\Billing\Usage;

use Carbon\CarbonInterface;
use Pushery\Billing\Contracts\UsageReporter;
use RuntimeException;

/**
 * The reporter for a driver that cannot bill usage. It refuses, loudly.
 *
 * There is no safe no-op here. A silent one would accept every unit of usage, report none of it, and
 * bill the customer nothing — the failure would surface as a month of missing revenue, discovered on
 * the invoice. Reaching this class at all means a tier meters something on a driver that cannot meter,
 * which the boot-time capability check exists to prevent; this is the backstop behind it.
 */
final readonly class NullUsageReporter implements UsageReporter
{
    public function report(
        string $customerReference,
        string $meterName,
        int $quantity,
        string $identifier,
        CarbonInterface $occurredAt,
    ): void {
        throw new RuntimeException("The active billing driver cannot report usage, so meter '{$meterName}' cannot be billed.");
    }

    public function reverse(string $meterName, string $identifier): void
    {
        throw new RuntimeException("The active billing driver cannot report usage, so meter '{$meterName}' cannot be adjusted.");
    }

    /**
     * Null, not a throw: there is simply no second source to read back from. A driver that bills usage
     * locally IS the authority, so a reconcile has nothing to compare against and correctly reports no drift
     * rather than a failure.
     */
    public function recordedTotal(
        string $customerReference,
        string $meterName,
        CarbonInterface $from,
        CarbonInterface $to,
    ): ?int {
        return null;
    }
}
