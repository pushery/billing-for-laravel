<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * One axis along which the package's records are scoped to a person: the morph columns that identify them,
 * the column that records their erasure, and what may happen to each table when they are erased.
 *
 * The package has more than one such axis, and that is the point. A BUYER owns a subscription and an
 * invoice; a MERCHANT is named on a payout statement and a commission invoice. Both are people with a right
 * to erasure and both appear on records the law requires the platform to keep, but they are not the same
 * person and they are not on the same rows — a merchant is not an owner, and treating them as one axis
 * would either erase a buyer's records when a merchant leaves or leave a merchant's data behind.
 *
 * Making the axis a VALUE is what keeps the two from drifting. Erasure, export and the retention clock all
 * take an axis and do the same work with it, so a table that one of them covers cannot be one the others
 * forget — the failure this whole area exists to prevent, in either direction: an export that misses a
 * table denies a person their data, an erasure that misses one keeps it.
 */
final readonly class ErasureAxis
{
    /**
     * @param  string  $name  the axis in one word, as it appears in diagnostics ('owner', 'merchant')
     * @param  list<string>  $purged  tables deleted outright
     * @param  list<string>  $retained  tables kept but unlinked, because the law requires them
     * @param  array<string, list<literal-string>>  $scrubbed  table => the columns whose contents are
     *                                                         personal data. Literal strings: a column name is interpolated into a query by name rather
     *                                                         than bound, so requiring a literal keeps that safe by construction rather than by review.
     * @param  array<string, array{parent: string, foreign_key: string}>  $cascaded  child table => its parent
     */
    public function __construct(
        public string $name,
        public string $typeColumn,
        public string $idColumn,
        public string $erasedAtColumn,
        public array $purged,
        public array $retained,
        public array $scrubbed,
        public array $cascaded,
    ) {}

    /**
     * Every table this person's data lives in — what an export has to cover to be a complete answer.
     *
     * Child tables are deliberately absent: they are keyed to a parent row rather than to the person, so
     * they are reached by joining through the parent instead of by filtering on the morph.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return [...$this->purged, ...$this->retained, ...array_keys($this->scrubbed)];
    }
}
