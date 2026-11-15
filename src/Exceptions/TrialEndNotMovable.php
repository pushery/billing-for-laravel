<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * The end of a trial that was not moved, with the reason a caller can branch on.
 *
 * `reason` is a fixed word per cause: `no_trial` where the owner has no subscription in its trial whose first run is
 * still ahead, and `not_ahead` where the moment asked for is not ahead of now. Nothing is written in either case.
 *
 * The messages are sentence text rather than behavior, so like every exception class this one opts out of
 * the three mutators that measure where a line of prose was wrapped. The values themselves are held by a
 * dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class TrialEndNotMovable extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function noTrial(): self
    {
        return new self('no_trial', 'The owner has no subscription in its trial whose first run is still ahead, so there is no trial end to move. A trial whose run is due may be collected already.');
    }

    public static function notAhead(string $moment): self
    {
        return new self('not_ahead', sprintf('A trial can be moved to end at a moment ahead of now only, and %s is not.', $moment));
    }
}
