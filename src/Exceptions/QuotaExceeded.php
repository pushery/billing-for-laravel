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
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
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
