<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * What a single go-live checkpoint reported.
 *
 * `Unreachable` is produced by the runner, never by a checkpoint: it means the point was not evaluated at
 * all because an earlier step still has an open blocking point. It is a distinct status rather than a
 * failure because the two demand different actions — a failure is fixed here, an unreachable point is fixed
 * somewhere earlier — and it is emphatically not a pass: a point nobody ran must never read as green.
 */
enum CheckpointStatus: string
{
    case Passed = 'pass';
    case Failed = 'fail';
    case Warned = 'warn';
    case Unreachable = 'unreachable';

    /**
     * Whether this status leaves the checklist able to proceed.
     *
     * TWO statuses stop it, not one. `Failed` is the obvious one; `Unreachable` stops it as well, and that
     * is deliberate rather than a side effect of the expression — a blocking point nobody could evaluate is
     * not a point that passed. `PreflightLine::isOpenBlocker()` and `PreflightReport::passed()` both say so
     * in their own words ("a blocking point that did not hold, or that was never reached").
     *
     * This used to read "only a real failure stops it", which invited exactly the wrong repair: making an
     * unreachable checkpoint satisfy the checklist would let a go-live proceed on a question that was never
     * answered, and nothing downstream would notice.
     */
    public function isSatisfied(): bool
    {
        return $this === self::Passed || $this === self::Warned;
    }
}
