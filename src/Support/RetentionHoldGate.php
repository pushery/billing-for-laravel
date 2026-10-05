<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Pushery\Billing\Contracts\RetentionHold;
use Pushery\Billing\Exceptions\RetentionHoldUnavailable;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Asks the host's {@see RetentionHold} which of the rows a query is about to destroy must be left alone.
 *
 * One place, so every deletion path in `billing:prune` answers the question the same way — the same
 * reason {@see SubjectScopedRecords} exists one level down. A gate written per call site is a gate that
 * gets forgotten at the next one, and the failure is invisible: that path simply keeps deleting.
 *
 * ## Bounded memory, whatever the table holds
 *
 * Candidate ids are read in chunks and the seam is asked per chunk, so a table with millions of expired
 * rows costs a bounded amount of memory rather than one id per row in a single array. What is accumulated
 * is the HELD set, and only the candidates the host named go into it.
 *
 * ## A hold can be large, so it is never bound one id at a time
 *
 * A legal hold is an exception somebody had to declare, but one declaration can cover a great deal: a tax
 * audit over several years holds every document of those years. A statement binds at most 65,535 parameters
 * on PostgreSQL and MySQL and 32,766 on SQLite, and a hold past that broke the whole run. {@see whereKeys()}
 * writes integer keys, which every table of this package has, into the statement instead.
 */
final readonly class RetentionHoldGate
{
    /** Candidates per question. Large enough that a normal prune asks once, small enough to stay bounded. */
    private const int CHUNK = 1000;

    public function __construct(private RetentionHold $hold) {}

    /**
     * The ids in this query that must survive.
     *
     * The query is cloned before it is read, so a caller can go on to use it for the deletion itself — and
     * so this can never leave an `orderBy` or a `select` behind on the caller's builder.
     *
     * @return list<int|string>
     *
     * @throws RetentionHoldUnavailable when the host's seam cannot answer — nothing may be deleted then
     */
    public function heldIn(string $recordType, Builder $query, string $key = 'id'): array
    {
        $held = [];

        $query->clone()->select($key)->orderBy($key)->chunk(
            self::CHUNK,
            /** @param Collection<int, stdClass> $rows */
            function (Collection $rows) use ($recordType, $key, &$held): void {
                // No empty-chunk guard: `chunk()` does not invoke this closure for an empty page, so a
                // branch for it would be one no test could enter — and an unreachable line cannot be told
                // apart from a wrong one.
                $ids = $this->identify($recordType, $rows, $key);

                try {
                    $named = array_fill_keys(array_map(strval(...), $this->hold->heldAmong($recordType, $ids)), true);
                } catch (Throwable $e) {
                    throw RetentionHoldUnavailable::asking($recordType, $e);
                }

                // The candidates the host named, as the table holds them: an id it was not asked about changes
                // nothing, and one it named as a string is the integer key it stands for.
                foreach ($ids as $id) {
                    if (isset($named[(string) $id])) {
                        $held[] = $id;
                    }
                }
            },
        );

        return $held;
    }

    /**
     * Narrow a query to the given keys, or with `$not` to everything else, without a placeholder per key.
     *
     * Integer keys are written into the statement, where no parameter limit applies; a list with any other key
     * stays bound as before. An empty list narrows to nothing, and with `$not` to everything.
     *
     * @param  list<int|string>  $keys
     */
    public static function whereKeys(Builder $query, string $column, array $keys, bool $not = false): Builder
    {
        $integers = array_filter($keys, is_int(...));

        if (count($integers) !== count($keys)) {
            return $query->whereIn($column, $keys, 'and', $not);
        }

        return $query->whereIntegerInRaw($column, $integers, 'and', $not);
    }

    /**
     * The chunk's primary keys, as something the seam can be asked about.
     *
     * A key that is neither an int nor a string ABORTS rather than being skipped, and that direction is the
     * whole point. Skipping it would leave one record the host was never asked about — which reads as "not
     * held" and deletes it. The one case this gate exists to prevent would then arrive through the gate
     * itself, quietly, for the rows it could not name.
     *
     * @param  Collection<int, stdClass>  $rows
     * @return list<int|string>
     */
    private function identify(string $recordType, Collection $rows, string $key): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $id = $row->{$key} ?? null;

            if (! is_int($id) && ! is_string($id)) {
                throw RetentionHoldUnavailable::asking(
                    $recordType,
                    new RuntimeException("A [{$recordType}] row has no usable [{$key}] to ask about."),
                );
            }

            $ids[] = $id;
        }

        return $ids;
    }
}
