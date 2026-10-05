<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A row found by its unique key or created, with a race for the key settled on every supported engine.
 *
 * Eloquent's `firstOrCreate()` reads, creates behind a savepoint, and after a unique violation reads again
 * without a lock. Inside a transaction on MySQL, whose default isolation is REPEATABLE READ, that read answers
 * from the snapshot of the transaction's first plain read, taken before the other writer committed, so it finds
 * nothing and the violation is thrown although the row exists. Webhook effects run inside a transaction, and
 * that is where two deliveries of one event race for a key. The read after a violation here is a locking read,
 * and a locking read sees the committed row on MySQL and PostgreSQL alike.
 */
final class UniqueRow
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|Closure(): array<string, mixed>  $values
     * @return TModel
     */
    public static function firstOrCreate(Builder $query, array $attributes, array|Closure $values = []): Model
    {
        $found = (clone $query)->where($attributes)->first();

        if ($found instanceof Model) {
            return $found;
        }

        $create = static fn (): Model => (clone $query)->create([...$attributes, ...($values instanceof Closure ? $values() : $values)]);
        $connection = $query->getConnection();

        try {
            // Behind a savepoint inside a transaction, as Eloquent does it: on PostgreSQL a failed statement
            // aborts the transaction around it, and only a savepoint keeps it usable for the read below.
            return $connection->transactionLevel() > 0 ? $connection->transaction($create) : $create();
        } catch (UniqueConstraintViolationException $collision) {
            // Re-thrown when the row is still not there: then some other unique index fired, and handing back
            // nothing would hide it.
            return (clone $query)->where($attributes)->lockForUpdate()->first() ?? throw $collision;
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|Closure(): array<string, mixed>  $values
     * @return TModel
     */
    public static function updateOrCreate(Builder $query, array $attributes, array|Closure $values = []): Model
    {
        $row = self::firstOrCreate($query, $attributes, $values);

        if (! $row->wasRecentlyCreated) {
            $row->fill($values instanceof Closure ? $values() : $values)->save();
        }

        return $row;
    }
}
