<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Takes a row under a lock, writing it first where it does not exist yet.
 *
 * The lock comes first. On MySQL an ignored insert of a row that exists leaves a shared lock on it, and two
 * transactions that each hold one wait for each other's exclusive lock, so writing first would let any two
 * concurrent writers deadlock. Reading first without a lock avoids that and costs more: the plain read opens the
 * transaction's snapshot before the wait for the lock, and every later plain read in the transaction misses what
 * the lock's holder committed meanwhile.
 *
 * Where the row does not exist yet, the locking read leaves a gap lock, and two transactions writing the same new
 * row at the same moment can still end one of them as a deadlock. That happens once in a row's life rather than on
 * every write, and the database reports it instead of letting both proceed. A row's life can be short: a usage
 * counter is new for every owner, meter and period, so the first requests of a period meet exactly this. The
 * transaction that takes a row through this class therefore runs up to ATTEMPTS times: the database rolls the loser
 * back, and run again it waits for the row the winner wrote and takes it.
 */
final class LockedRow
{
    /**
     * How often a transaction that takes a row through this class runs before a deadlock is passed on.
     *
     * The framework runs a transaction again only where it is the outermost one, since the database rolled all of it
     * back; inside another, the deadlock goes up to that one.
     */
    public const int ATTEMPTS = 3;

    /**
     * The row $query names, locked for the caller's transaction, written from $values first when it is absent.
     * $created says whether this call wrote it.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $values
     *
     * @param-out  bool  $created
     *
     * @return TModel
     */
    public static function take(Builder $query, array $values, ?bool &$created = null): Model
    {
        $created = false;
        $row = (clone $query)->lockForUpdate()->first();

        if ($row instanceof Model) {
            return $row;
        }

        $created = $query->getModel()->newQuery()->insertOrIgnore($values) === 1;

        return (clone $query)->lockForUpdate()->firstOrFail();
    }
}
