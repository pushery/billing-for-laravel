<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\Enums\MeteringPolicy;
use RuntimeException;

/**
 * A metered request was refused because it would take the owner past a BLOCKING allowance (a hard-stop
 * or refuse policy). A degrading or fair-use meter never raises this — those keep serving and are just
 * flagged. Carries the meter, the policy and how much of the allowance was left, so a caller can render
 * a precise "you have N left" rather than a bare 429.
 */
final class QuotaExceeded extends RuntimeException
{
    public function __construct(
        public readonly string $meterKey,
        public readonly MeteringPolicy $policy,
        public readonly int $remaining,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function onMeter(string $meterKey, MeteringPolicy $policy, int $requested, int $remaining): self
    {
        return new self(
            $meterKey,
            $policy,
            $remaining,
            "Quota exceeded on meter '{$meterKey}': {$requested} requested, {$remaining} remaining in the allowance."
        );
    }
}
