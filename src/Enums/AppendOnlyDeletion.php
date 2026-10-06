<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Whether a caller can delete an append-only row through its model, and under what condition.
 *
 * The answer is about the model alone. The guard behind it reads what is done through a model instance,
 * and the package's own retention and erasure delete by query, which raises no model event. What they
 * remove, and when, is for the retention matrix to say, not for this enum.
 *
 * This enum exists so the answer is never given by ABSENCE. A model that spells out the append-only rule by
 * hand can simply lack a deletion arm, and a missing hook throws no error and reads exactly like a considered
 * decision: one archive could refuse deletion while its sibling under the same retention rule did not, and
 * nobody would notice, because there would be no place where the question is asked.
 *
 * Naming the answer makes it a line in the model rather than a gap in it.
 */
enum AppendOnlyDeletion: string
{
    /**
     * A delete through the model goes through inside `purging()`, and nowhere else.
     *
     * The door for a host that has to take one row out by hand. The package does not come through it:
     * where it removes such rows, on a retention window or with an erased owner, it deletes by query.
     */
    case PurgingOnly = 'purging_only';

    /**
     * No delete through the model, inside `purging()` or not.
     *
     * For a row no caller should be able to take away: evidence an audit is answered from, or a movement
     * a balance is the sum of. It says nothing about the retention schedule. Where the matrix gives the
     * table a window, `billing:prune` removes the row by query once the window has passed.
     */
    case Never = 'never';
}
