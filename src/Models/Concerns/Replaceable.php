<?php

declare(strict_types=1);

namespace Pushery\Billing\Models\Concerns;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Support\BillingConnection;

/**
 * The seam through which a host replaces a billing model with its own subclass.
 *
 * `billing.models` maps the package class to the host's, and the package reaches its models
 * only through model() and resolve(). So the subclass comes back on every path — a query, a new
 * row, a relation — and not only where the host queries it itself. One path that named
 * the package class directly would give the host two views of one table.
 *
 * @phpstan-require-extends Model
 */
trait Replaceable
{
    /**
     * The class the package uses for this model: the host's configured subclass, or this class.
     *
     * A configured class that does not exist, or does not extend this one, is ignored rather than
     * obeyed. Obeying it would fail in the middle of a request or a queued job, long after boot,
     * and this class loses the least.
     *
     * The container rather than `config()`: the helper belongs to laravel/framework, which this
     * package does not require. Outside an application, where nothing is bound, the answer is
     * this class.
     *
     * The key is `static::class`, not `self::class`: a package model extending another one must
     * find its own entry, not its parent's.
     *
     * @return class-string<static>
     */
    public static function model(): string
    {
        $container = Container::getInstance();

        $models = $container->bound(Repository::class)
            ? $container->make(Repository::class)->get('billing.models')
            : null;

        $configured = is_array($models) ? ($models[static::class] ?? null) : null;

        return is_string($configured) && is_subclass_of($configured, static::class)
            ? $configured
            : static::class;
    }

    /**
     * The connection the model reads and writes on: its own where it names one, a host's subclass included, and
     * otherwise the connection the package's tables live on ({@see BillingConnection}), the default one unless
     * `billing.database.connection` names another. A connection named by an enum is read as its value, the way
     * Eloquent reads it.
     *
     * @return string|null
     */
    public function getConnectionName()
    {
        return parent::getConnectionName() ?? BillingConnection::name();
    }

    /**
     * A new, unsaved instance of the configured class.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function resolve(array $attributes = []): static
    {
        $class = static::model();

        return new $class($attributes);
    }
}
