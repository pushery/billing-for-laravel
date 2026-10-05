<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Exceptions\RetentionHoldUnavailable;
use Pushery\Billing\ValueObjects\ErasureAxis;

/**
 * The table work of erasure, export and the retention clock, done once and driven by an axis.
 *
 * Erasure and export are the same map read in two directions, and the retention clock is the same map read
 * a third time. Written separately, each would carry its own copy of "which tables, joined how, filtered on
 * which columns" — and the copies drift. The drift is not loud: an export that misses a table denies a
 * person data they are entitled to, an erasure that misses one keeps data it promised to delete, and both
 * report success either way. One implementation, taking the axis as a parameter, makes those copies
 * impossible rather than merely discouraged.
 *
 * It is also what lets a second axis be trusted before it has any rows. Nothing here knows the word "owner"
 * — every column name arrives with the axis — so the buyer axis, which the whole suite exercises daily, is
 * the same code path the merchant axis will run.
 */
final readonly class SubjectScopedRecords
{
    /**
     * Delete the child tables of this axis, joined through their parents.
     *
     * They must go FIRST, while the parent rows still exist to join through. Why this is not left to the
     * foreign key is recorded on the map itself.
     *
     * @return array<string, int> table => rows deleted
     */
    public function purgeCascaded(ErasureAxis $axis, Model $subject): array
    {
        $deleted = [];

        foreach ($axis->cascaded as $table => $link) {
            $deleted[$table] = DB::table($table)
                ->whereIn($link['foreign_key'], $this->scoped($axis, $link['parent'], $subject)->select('id'))
                ->delete();
        }

        return $deleted;
    }

    /**
     * Delete the operational rows outright.
     *
     * @return array<string, int> table => rows deleted
     */
    public function purge(ErasureAxis $axis, Model $subject): array
    {
        $deleted = [];

        foreach ($axis->purged as $table) {
            $deleted[$table] = $this->scoped($axis, $table, $subject)->delete();
        }

        return $deleted;
    }

    /**
     * Null the personal columns of rows that themselves have to survive.
     *
     * @return array<string, int> table => rows touched
     */
    public function scrub(ErasureAxis $axis, Model $subject): array
    {
        $scrubbed = [];

        foreach ($axis->scrubbed as $table => $columns) {
            $values = array_fill_keys($columns, null);

            // Where the axis names a stamp, the scrub records when it happened, so a later write can tell a
            // value that was scrubbed from one that was never there and leave the first alone.
            if (isset($axis->scrubbedAt[$table])) {
                $values[$axis->scrubbedAt[$table]] = Carbon::now();
            }

            // Only rows that still hold something: counting rows already scrubbed would report work that did
            // not happen, and this count is what an erasure receipt is written from. The alternatives are
            // NESTED — at the top level an `orWhere` would sit beside the two morph conditions and turn
            // "this person's rows that hold data" into "this person's rows, OR anyone's row that holds
            // data", scrubbing strangers.
            $scrubbed[$table] = $this->scoped($axis, $table, $subject)
                ->where(static function (Builder $q) use ($columns): void {
                    foreach ($columns as $column) {
                        $q->orWhereNotNull($column);
                    }
                })
                ->update($values);
        }

        return $scrubbed;
    }

    /**
     * Keep the financial records and cut them loose from the person, stamping when that happened.
     *
     * The stamp is what the retention clock later reads: a record with no erasure date belongs to somebody
     * who is still here, and pruning it would delete a live customer's invoice.
     *
     * @return array<string, int> table => rows unlinked
     */
    public function unlink(ErasureAxis $axis, Model $subject, Carbon $at): array
    {
        $unlinked = [];
        // One key for this person, the same in every table that keeps one, and drawn at random so that it leads
        // back to nobody.
        $key = bin2hex(random_bytes(16));

        foreach ($axis->retained as $table) {
            $keyColumn = $axis->erasedKeys[$table] ?? null;

            $unlinked[$table] = $this->scoped($axis, $table, $subject)->update([
                $axis->typeColumn => null,
                $axis->idColumn => null,
                $axis->erasedAtColumn => $at,
                ...($keyColumn === null ? [] : [$keyColumn => $key]),
            ]);
        }

        return $unlinked;
    }

    /**
     * Everything the package holds about this person along this axis, as plain rows.
     *
     * @return array<string, list<array<array-key, mixed>>>
     */
    public function export(ErasureAxis $axis, Model $subject): array
    {
        $export = [];

        foreach ($axis->all() as $table) {
            $export[$table] = $this->rows($this->scoped($axis, $table, $subject));
        }

        // Child tables key on their parent row rather than on the person, so the filter above cannot see
        // them — they are reached by joining through the parent. They are still this person's data, and the
        // eraser reads the same map, so a child table cannot be covered by one side and forgotten by the other.
        foreach ($axis->cascaded as $table => $link) {
            $export[$table] = $this->rows(DB::table($table)->whereIn(
                $link['foreign_key'],
                $this->scoped($axis, $link['parent'], $subject)->select('id'),
            ));
        }

        return $export;
    }

    /**
     * Delete the retained records of ALREADY-ERASED people whose statutory window has run out.
     *
     * Two conditions, both required. The person must have been erased — a record still linked to a living
     * customer is not the retention clock's business. And the window must have passed, measured from the
     * document's own issue date rather than from when the row happened to be written.
     *
     * The column names are typed `literal-string` because they are interpolated into raw SQL — a column
     * name cannot be bound as a parameter. Requiring a literal is what keeps that interpolation safe by
     * construction rather than by the caller remembering: a value that reached here from a request could
     * not satisfy the type.
     *
     * A record the host holds is left where it is, however far past the window it is. The two duties are
     * both real and they point opposite ways — storage limitation says delete, a legal hold says preserve —
     * so the host, which is the side that received the order, decides per record. The gate is a REQUIRED
     * parameter rather than an optional one: a seam a caller may forget is a seam that silently stops being
     * asked, and the symptom of forgetting it here is a destroyed record nobody can get back.
     *
     * A record whose document also lives as a file takes the file with it, through `$files`, after the row.
     *
     * @param  array<string, literal-string>  $issueColumns  table => the column holding its issue date
     * @param  array<string, string>  $cutoffs  table => the cutoff for that table where its own window is longer than `$cutoff`
     *
     * @throws RetentionHoldUnavailable when the host's seam cannot answer
     */
    public function pruneExpired(
        ErasureAxis $axis,
        string $cutoff,
        array $issueColumns,
        bool $dryRun,
        RetentionHoldGate $holds,
        array $cutoffs = [],
        ?StoredDocumentFiles $files = null,
    ): int {
        $count = 0;

        foreach ($axis->retained as $table) {
            $issueColumn = $issueColumns[$table] ?? 'created_at';

            $before = $cutoffs[$table] ?? $cutoff;

            // A record with no explicit issue date falls back to when it was created — a null issue date must
            // never read as "infinitely old" and prune early. Written through the builder rather than as a raw
            // COALESCE, so both columns are quoted like any other and no name is spliced into SQL text.
            $rows = DB::table($table)
                ->whereNotNull($axis->erasedAtColumn)
                ->where(static fn (Builder $dated): Builder => $dated
                    ->where($issueColumn, '<', $before)
                    ->orWhere(static fn (Builder $undated): Builder => $undated->whereNull($issueColumn)->where('created_at', '<', $before)));

            $held = $holds->heldIn($table, $rows);

            if ($held !== []) {
                RetentionHoldGate::whereKeys($rows, 'id', $held, not: true);
            }

            $count += match (true) {
                ! $files instanceof StoredDocumentFiles => $dryRun ? $rows->count() : $rows->delete(),
                $dryRun => $files->countWithFiles($table, $rows),
                default => $files->deleteWithFiles($table, $rows),
            };
        }

        return $count;
    }

    /** One table, filtered to the rows this person is on along this axis. */
    private function scoped(ErasureAxis $axis, string $table, Model $subject): Builder
    {
        return DB::table($table)
            ->where($axis->typeColumn, $subject->getMorphClass())
            ->where($axis->idColumn, $subject->getKey());
    }

    /** @return list<array<array-key, mixed>> */
    private function rows(Builder $query): array
    {
        return array_values($query->get()->map(fn (object $row): array => (array) $row)->all());
    }
}
