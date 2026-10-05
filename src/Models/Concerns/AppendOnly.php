<?php

declare(strict_types=1);

namespace Pushery\Billing\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\AppendOnlyDeletion;
use RuntimeException;

/**
 * "This row is written once and never changed" — said once, instead of ten times by hand.
 *
 * ## What the guard sees
 *
 * It listens to the model's `updating` and `deleting` events, so it refuses what a caller does through a
 * model instance: `$row->save()`, `$row->delete()`. A query raises neither event. `Model::query()->delete()`,
 * `DB::table()->delete()` and a cascading foreign key all pass it unseen, and that is how the package's own
 * retention and erasure remove rows. The guard keeps application code from changing a record by accident.
 * It is not a lock on the table.
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
 * The MECHANISM is here; the JUDGMENTS stay with the model, because they are statements about that record
 * and not shared code:
 *
 * - which columns may still move (`appendOnlyMutableColumns()`, empty by default — the whole row is frozen),
 * - what to say when a caller tries anyway (`appendOnlyUpdateRefusal()`),
 * - whether a caller may delete it through the model at all (`appendOnlyDeletion()`), and what to say when
 *   that is refused.
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
     * per class-that-uses-the-trait, so opening the door on one model does not open it on another.
     */
    private static bool $appendOnlyPurging = false;

    /**
     * Delete a row through its model where the guard would otherwise refuse.
     *
     * It opens the door for a delete made on a model instance inside the callback, on a model that declares
     * {@see AppendOnlyDeletion::PurgingOnly}. A query in the callback was never behind the door, and a model
     * that declares {@see AppendOnlyDeletion::Never} stays shut. The package's own retention and erasure
     * delete by query and do not come through here: this is for a host that has to take one row out by hand.
     *
     * The flag is reset in `finally`, so a callback that throws does not leave the door open for whatever
     * runs next in the same process. That is the reason this is a method rather than a public flag.
     *
     * The template hands the callback's return type on to the caller, whose result is `mixed` without it.
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

    /** Whether a caller can delete this row through the model, and under what condition. */
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

    /**
     * What to tell a caller whose delete was refused.
     *
     * It says what does remove the row, where anything does. AppendOnlyRuleHasOneHomeTest holds that
     * against the retention matrix and the foreign keys.
     */
    abstract protected static function appendOnlyDeleteRefusal(): string;
}
