<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A dunning rung carries a late fee, and the active billing driver charges none.
 *
 * Like MeteringUnsupported, this refuses to boot rather than degrade. The dunning advance would still climb
 * the ladder, still record the fee in the audit log and still pass it to the suspension warning, while no fee
 * was ever added to anything the customer is charged.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class LateFeesUnsupported extends RuntimeException
{
    public static function forDriver(string $driver, string $rung): self
    {
        return new self(
            "The dunning rung '{$rung}' carries a late fee, but the active billing driver '{$driver}' ".
            'charges no late fees, so the fee would be announced and never collected. '.
            'Remove the fee from that rung in billing.dunning on this driver.'
        );
    }
}
