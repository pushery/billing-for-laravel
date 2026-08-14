<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Which mechanism actually carries out a retention rule.
 *
 * The matrix is a DECLARATION, and for a long time that was all it was: rules were written there, printed
 * by `billing:prune --dry-run` under a heading calling them "the record of what this run enforces", and
 * nothing connected them to a mechanism. Two of them were enforced by nobody. The dry-run said otherwise
 * and no test could go red, because the only test compared the declared NUMBER of days against config.
 *
 * The failure was structural rather than an oversight: declaration and execution were two lists with no
 * link, so a new period-scoped document type gets a rule and no deletion path, every time, silently.
 *
 * Stating the executor on the rule is what closes that. It cannot be derived from the rule's own shape —
 * `billing_place_evidence` carries the same Delete/CreatedAt signature as the export documents and is
 * deleted by the erasure axis, so a pruner that inferred responsibility from the signature would touch it
 * a second time and behind the axis's back.
 */
enum RetentionExecutor: string
{
    /**
     * The time pruner in `billing:prune` deletes these rows once their own clock has run out.
     *
     * For records nobody owns — a document naming a PERIOD rather than a person, which no erasure axis can
     * ever reach because there is no subject to erase.
     */
    case TimePruner = 'time_pruner';

    /**
     * An erasure axis carries this one: the rows go, or are unlinked, when their subject is erased.
     *
     * The clock here belongs to a person's request, not to a calendar.
     */
    case ErasureAxis = 'erasure_axis';

    /**
     * A named path in `billing:prune` written for this rule specifically, because it needs more than a
     * cutoff — the audit ledger, for instance, waits for BOTH its owner to be erased and its window to
     * expire, and neither alone is enough.
     */
    case DedicatedPruner = 'dedicated_pruner';

    /**
     * Nothing deletes this, because nothing may have stored it. The rule states a duty to discard at the
     * point of receipt; the pruner's job is to prove the duty was met, not to clean up after it.
     */
    case NeverStored = 'never_stored';
}
