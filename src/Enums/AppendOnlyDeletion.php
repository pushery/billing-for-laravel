<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Whether an append-only row can ever be deleted, and under what condition.
 *
 * This enum exists because the answer used to be given by ABSENCE. Ten models spelled out the same
 * append-only rule by hand; three had a deletion arm and seven simply had none, and a missing hook throws no
 * error and reads exactly like a considered decision. `ReportingExportRecord` had no arm while its sibling
 * archive `TaxReturnExportRecord` did, with the retention matrix holding both under one rule — a divergence
 * nobody noticed, because there was no place where the question was ever asked.
 *
 * Naming the answer makes it a line in the model rather than a gap in it.
 */
enum AppendOnlyDeletion: string
{
    /**
     * Deletable, but only from inside `purging()` — retention or an erasure sweep.
     *
     * The ordinary case for a row that carries a statutory window: it goes when the window runs out, and
     * never because a caller asked.
     */
    case PurgingOnly = 'purging_only';

    /**
     * Never, by any path, including retention.
     *
     * For a row that is unlinked from an erased person rather than removed — the record of what happened
     * survives with nobody's name on it.
     */
    case Never = 'never';
}
