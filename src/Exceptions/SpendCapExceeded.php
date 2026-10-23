<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\Enums\MeteringPolicy;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\SpendCapCheck;

/**
 * A metered request was refused because what it would cost does not fit under the owner's spend cap.
 *
 * A QuotaExceeded, so a caller that already handles a refused quota handles this one too. Caught on its own, it
 * carries what the request would have cost and what is left under the cap. Any meter can raise it, whatever its
 * policy, because the cap measures money rather than units.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class SpendCapExceeded extends QuotaExceeded
{
    public function __construct(
        string $meterKey,
        MeteringPolicy $policy,
        int $remaining,
        public readonly Money $cost,
        public readonly Money $remainingSpend,
        string $message,
    ) {
        parent::__construct($meterKey, $policy, $remaining, $message);
    }

    /** The refusal of a request the check did not admit, with the units the allowance still had. */
    public static function fromCheck(string $meterKey, MeteringPolicy $policy, int $remaining, SpendCapCheck $check): self
    {
        $left = $check->remaining();

        return new self(
            $meterKey,
            $policy,
            $remaining,
            $check->cost,
            $left,
            "Spend cap reached on meter '{$meterKey}': the request would cost {$check->cost->format()}, {$left->format()} is left under the cap."
        );
    }
}
