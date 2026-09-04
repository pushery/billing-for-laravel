<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * The shipped rate snapshot does not match the digest recorded alongside it.
 *
 * Thrown rather than recovered from, and there is no "carry on with what we found" path on purpose. The
 * failure this catches is not a corrupted download — Composer already covers that. It is a digit changed
 * inside `vendor/`, which appears in no diff because `vendor/` is in no diff, and which would silently
 * reprice every invoice to a country while leaving the money as the only trace.
 *
 * Refusing to price is worse than pricing correctly and better than pricing wrongly, because a refusal is
 * seen the same day and a wrong rate is seen at an audit.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class TaxRateSnapshotTampered extends RuntimeException
{
    public static function unreadable(string $path): self
    {
        return new self(
            "The tax rate snapshot at {$path} could not be read or is not the shape one takes. It is the "
            .'source every invoice is priced from, so pricing stops here rather than falling back to '
            .'something that happens to be lying around.'
        );
    }

    public static function digestMismatch(string $path, string $recorded, string $actual): self
    {
        return new self(
            "The tax rate snapshot at {$path} has been edited since it was recorded: it carries the digest "
            ."{$recorded} but its rates hash to {$actual}. If the change was intended, re-record the "
            .'snapshot through the importer so the header says who accepted it — an edited table with a '
            .'stale digest is a table nobody has vouched for.'
        );
    }
}
