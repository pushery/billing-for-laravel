<?php

declare(strict_types=1);

namespace Pushery\Billing\Models\Concerns;

/**
 * Writes every moment of the model with its offset on PostgreSQL, where the package keeps its moments as `timestamptz`.
 *
 * PostgreSQL reads a literal without an offset in the time zone of the session. A `created_at` that Eloquent writes in
 * the application's time zone, or a moment that UtcDateTime writes in UTC, would therefore be stored as the moment that
 * wall clock shows in the session's zone, off by the difference between the two. With its offset the literal names its
 * moment, whatever zone the session runs in.
 *
 * MySQL and SQLite keep the format without one. MySQL converts an offset in a DATETIME literal to the session's zone,
 * and SQLite compares the text, which an offset would change.
 */
trait StoresMomentsWithTheirOffset
{
    public function getDateFormat(): string
    {
        return $this->getConnection()->getDriverName() === 'pgsql' ? 'Y-m-d H:i:sP' : parent::getDateFormat();
    }
}
