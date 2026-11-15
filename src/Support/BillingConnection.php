<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Models\Concerns\Replaceable;

/**
 * The database connection the package's tables live on.
 *
 * `billing.database.connection`, and null for the default connection, as before the setting existed. A host that keeps
 * a database per tenant switches its default connection once a tenant is initialized, while the package's tables stay
 * in the central database, because the owner of a subscription is the tenant itself. Naming the central connection here
 * keeps every read and write of the package there: a model's ({@see Replaceable}), and a
 * query's, a transaction's and a lookup of the schema that go through this class.
 *
 * The container rather than `config()`, as for the models: outside an application nothing is bound, and the answer is
 * then the default connection.
 */
final class BillingConnection
{
    /** The name of the connection the package's tables live on, or null for the default connection. */
    public static function name(): ?string
    {
        $container = Container::getInstance();
        $name = $container->bound(Repository::class) ? $container->make(Repository::class)->get('billing.database.connection') : null;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /** The connection the package's tables live on. */
    public static function connection(): Connection
    {
        return DB::connection(self::name());
    }
}
