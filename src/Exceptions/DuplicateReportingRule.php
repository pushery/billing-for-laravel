<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * Two plausibility rules answer to one key.
 *
 * A rule's key is half of an acknowledgement's key, so two rules sharing one make an acknowledgement
 * ambiguous: answering the finding clears whichever rule happened to raise it, and the other one comes back
 * on the next run. That reads as a broken acknowledgement rather than as a broken catalog, which is the
 * expensive way to discover it — so the catalog refuses to assemble instead.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class DuplicateReportingRule extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self(
            "Two reporting plausibility rules use the key '{$key}'. A key is half of an acknowledgement's "
            .'identity, so a shared one makes an answered finding come back on the next run. Give the added '
            .'rule a key of its own.'
        );
    }
}
