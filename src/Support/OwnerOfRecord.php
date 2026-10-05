<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The model a billing record names through its morph columns, found even where the application hides it.
 *
 * Usage that was recorded, a sale that was settled, a share that is held for a merchant: each stands in the name of
 * an owner, and the obligation outlives the owner's place in the application. An application that soft-deletes its
 * customers or scopes them to a tenant hides the row from its own queries, and a lookup through those queries answers
 * "not found" for an owner who still has a provider customer and still owes, or is owed, the money. So this lookup
 * ignores the application's global scopes. It answers null only where there is no such model: the stored type names
 * no model class, or no row carries the key.
 *
 * A path that starts something new for an owner, a renewal or a reminder, is not settling a record, and reads the
 * owner through the application's own queries.
 */
final class OwnerOfRecord
{
    public static function find(?string $type, mixed $id): ?Model
    {
        if ($type === null || $type === '' || (! is_int($id) && ! is_string($id))) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_a($class, Model::class, true)) {
            return null;
        }

        $owner = $class::query()->withoutGlobalScopes()->find($id);

        return $owner instanceof Model ? $owner : null;
    }
}
