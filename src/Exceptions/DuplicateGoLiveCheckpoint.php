<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use LogicException;

/**
 * Two go-live checkpoints claim the same key.
 *
 * This is refused rather than resolved, because either resolution is worse than stopping. Letting the later
 * registration win would let a consumer neutralize a blocking point by registering a passing one under its
 * key — the checklist would then report a green line for a condition nobody checked. Letting the earlier win
 * would silently drop the consumer's own point. A key collision is a programming error with a one-line fix,
 * so it is raised where it happens instead of being carried into a report.
 *
 * The sanctioned way to switch a blocking point off is the waiver list, which is deliberate, named in
 * configuration, and reported as a warning on every run.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class DuplicateGoLiveCheckpoint extends LogicException
{
    public static function key(string $key): self
    {
        return new self(
            "Two go-live checkpoints are registered under the key [{$key}]. A key identifies a point in the ".
            'preflight report, in the boot refusal and in the waiver list, so it must be unique. Rename the '.
            'checkpoint you added, or — to switch a point off deliberately — leave it in place and add its '.
            'key to billing.marketplace.preflight.waived.'
        );
    }
}
