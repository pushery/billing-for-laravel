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
 * One place, so the four deletion paths in `billing:prune` cannot answer the question differently — the
 * same reason {@see SubjectScopedRecords} exists one level down. A gate written per call site is a gate
 * that gets forgotten at the fifth one, and the failure is invisible: that path simply keeps deleting.
 *
 * ## Bounded memory, whatever the table holds
 *
 * Candidate ids are read in chunks and the seam is asked per chunk, so a table with millions of expired
 * rows costs a bounded amount of memory rather than one id per row in a single array. What is accumulated
 * is the HELD set, and that is small by nature: a legal hold is an exception somebody had to declare.
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
                    $held = [...$held, ...$this->hold->heldAmong($recordType, $ids)];
                } catch (Throwable $e) {
                    throw RetentionHoldUnavailable::asking($recordType, $e);
                }
            },
        );

        return $held;
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
