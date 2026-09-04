<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A reporting period names a seller whose model cannot be found, and that is a data error rather than an
 * empty answer.
 *
 * The pair came off a SETTLEMENT DOCUMENT — money was paid against it — and the class it names still exists,
 * because that is checked first and a vanished class is skipped for its own stated reason. What is left is a
 * row pointing at a record that is gone, and there is no reading under which a reporting run should carry on.
 *
 * Raised rather than skipped, and the difference is the whole point. Skipping removes a seller from a filing
 * without saying so — the under-reporting direction, which is the offense the duty exists to prevent, and
 * the one nobody notices because the report still looks complete.
 *
 * Global scopes are already taken off before this can be reached, so a soft-deleted or tenant-scoped seller
 * is NOT what lands here. A closed account still owes a return for the year it was open.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class SellerModelMissing extends RuntimeException
{
    public static function for(string $ownerType, string $ownerId): self
    {
        return new self(
            "A settlement document names the seller {$ownerType}#{$ownerId}, and no such record exists. "
            .'The reporting period is refused rather than assembled without them: money was settled against '
            .'that pair, so leaving them out would file a return that is short by a whole seller and looks '
            .'complete. Restore the record, or unlink the documents from it the way erasure does.'
        );
    }
}
