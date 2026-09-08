<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when the host's legal-hold seam could not answer, so the pruner does not know what it is allowed
 * to destroy.
 *
 * This is the fail-closed direction made explicit. The alternative — treating an unreachable hold seam as
 * "nothing is held" — would delete precisely the records the seam exists to protect, and would do it
 * silently at the one moment the host had lost the ability to object.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping a
 * fragment measures where the line was wrapped, not what a test asserts.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class RetentionHoldUnavailable extends RuntimeException
{
    public static function asking(string $recordType, Throwable $previous): self
    {
        return new self(
            "The legal-hold seam could not say which [{$recordType}] records are held, so none were pruned.",
            previous: $previous,
        );
    }
}
