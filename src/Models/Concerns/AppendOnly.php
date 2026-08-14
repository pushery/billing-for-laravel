<?php

declare(strict_types=1);

namespace Pushery\Billing\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\AppendOnlyDeletion;
use RuntimeException;

/**
 * "This row is written once and never changed" — said once, instead of ten times by hand.
 *
 * ## What it replaces, and why the copies were a problem in fact rather than in principle
 *
 * Ten models spelled the same rule out in their own `booted()`. Individually each was correct; together they
 * had already drifted, and the drift was invisible:
 *
 * - Three had a deletion arm and seven had none. `ReportingExportRecord` had none while its sibling archive
 *   `TaxReturnExportRecord` did — and the retention matrix holds both under one rule. A missing hook throws
 *   no error, so the gap read exactly like a decision somebody made.
 * - The three arms disagreed on shape: `bool` against `void`, `if (! purging) throw` against
 *   `if (purging) return`, `static::` against `self::`.
 * - `purging()` was implemented twice with byte-identical bodies. Not two flavors of a pattern — one
 *   function typed out twice.
 *
 * ## What each model still answers for itself
 *
 * The MECHANISM is here; the JUDGEMENTS stay with the model, because they are statements about that record
 * and not shared code:
 *
 * - which columns may still move (`appendOnlyMutableColumns()`, empty by default — the whole row is frozen),
 * - what to say when a caller tries anyway (`appendOnlyUpdateRefusal()`),
 * - whether deletion is possible at all (`appendOnlyDeletion()`), and what to say when it is refused.
 *
 * The refusal methods are ABSTRACT on purpose. A default message would be the drift returning by another
 * door: every model would inherit a sentence that says nothing about why this particular record is frozen,
 * and the reader who hits it would learn nothing.
 */
trait AppendOnly
{
    /**
     * True only inside {@see self::purging()}.
     *
     * On the trait rather than on each model, which also fixes something the copies got wrong: the flag is
     * per class-that-uses-the-trait, so one model's retention sweep cannot open the door for another's.
     */
    private static bool $appendOnlyPurging = false;

    /**
     * Run a deletion the guard would otherwise refuse — retention, or an erasure sweep.
     *
     * The flag is reset in `finally`, so a callback that throws does not leave the door open for whatever
     * runs next in the same process. That detail was correct in both hand-written copies and is the reason
     * this is a method rather than a public flag.
     *
     * The template is load-bearing rather than decoration: without it every caller's result is `mixed`, and
     * the prune command's own count line stops type-checking. Moving the method here dropped the annotation
     * once, and PHPStan named the exact line — worth keeping in mind for the next method that moves.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function purging(callable $callback): mixed
    {
        self::$appendOnlyPurging = true;

        try {
            return $callback();
        } finally {
            self::$appendOnlyPurging = false;
        }
    }

    /** Laravel calls this for every model using the trait, alongside the model's own `booted()`. */
    protected static function bootAppendOnly(): void
    {
        static::updating(static function (Model $record): void {
            $touched = array_keys($record->getDirty());
            $refused = array_values(array_diff($touched, static::appendOnlyMutableColumns()));

            if ($refused !== []) {
                throw new RuntimeException(static::appendOnlyUpdateRefusal($refused));
            }
        });

        static::deleting(static function (): bool {
            if (static::appendOnlyDeletion() === AppendOnlyDeletion::PurgingOnly && self::$appendOnlyPurging) {
                return true;
            }

            throw new RuntimeException(static::appendOnlyDeleteRefusal());
        });
    }

    /**
     * Columns that may still change after the row was written. Empty means the whole row is frozen.
     *
     * The usual reason for an entry is an erasure unlinking a person from a record whose CONTENT does not
     * move — which is not an edit of what happened.
     *
     * @return list<string>
     */
    protected static function appendOnlyMutableColumns(): array
    {
        return [];
    }

    /** Whether this row can ever be deleted, and under what condition. */
    protected static function appendOnlyDeletion(): AppendOnlyDeletion
    {
        return AppendOnlyDeletion::PurgingOnly;
    }

    /**
     * What to tell a caller who tried to change a frozen column.
     *
     * @param  list<string>  $columns  the columns that were refused, so the message can name them
     */
    abstract protected static function appendOnlyUpdateRefusal(array $columns): string;

    /** What to tell a caller who tried to delete the row outside `purging()`. */
    abstract protected static function appendOnlyDeleteRefusal(): string;
}
