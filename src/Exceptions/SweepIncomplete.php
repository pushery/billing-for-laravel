<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A scheduled sweep tried every entry, and some of them failed.
 *
 * Each failure was reported through the application's exception handler when it happened, and each entry it hit keeps
 * no mark, so the next run tries it again. The other entries were handled. The command that ran the sweep ends with
 * this exception, so a scheduler counts the run as failed.
 *
 * The message is sentence text rather than behavior, so like every exception class this one opts out of the three
 * mutators that measure where a line of prose was wrapped. The values themselves are held by a dedicated guard that
 * varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class SweepIncomplete extends RuntimeException
{
    public function __construct(public readonly int $handled, public readonly int $failed)
    {
        parent::__construct(sprintf('%d of %d entries failed, and the next run tries them again.', $failed, $handled + $failed));
    }
}
