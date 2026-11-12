<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Pushery\SQLens\Attributes\NoSqlOnDriver;

/**
 * The webhook deliveries and the runs of their effects keep every moment as `timestamptz` on PostgreSQL, in
 * payments-only mode as well.
 *
 * The payments-only counterpart of `keep_every_moment_of_the_billing_tables_as_timestamptz`, which converts these two
 * tables together with the package's others and so does not run in payments-only mode. This one converts the two
 * tables alone, the same way: each column from the zone its values were written in, which for every column of these
 * two tables is the application's time zone, the one Eloquent wrote it in, with the session in UTC and each table in a
 * transaction of its own.
 *
 * It reads which columns are still a timestamp, so where the full-mode migration ran first, as it does in full mode,
 * where both run in their order, it finds nothing left to convert. An installation that switches from payments-only
 * to full mode later finds these two tables converted, and the full-mode migration converts the rest.
 *
 * MySQL and SQLite stay as they are, as in the full-mode migration.
 *
 * The rollback keeps the columns as they are. In full mode the full-mode migration converted them, and its own rollback,
 * which runs after this one's, turns them back; turning them back here would undo its work on these two tables while
 * its own record still says it is applied. In payments-only mode the moments keep their zone.
 *
 * Server-only.
 */
return new
#[NoSqlOnDriver('mysql', reason: 'timestamptz is a PostgreSQL type; MySQL keeps these columns as they are')]
#[NoSqlOnDriver('mariadb', reason: 'timestamptz is a PostgreSQL type; MariaDB keeps these columns as they are')]
#[NoSqlOnDriver('sqlite', reason: 'timestamptz is a PostgreSQL type; SQLite keeps these columns as they are')]
class extends Migration
{
    /**
     * Running the tables in transactions of their own rather than in the one a migration runs in, so that no table
     * stays locked while the other changes.
     */
    public $withinTransaction = false;

    /**
     * The tables of payments-only mode.
     *
     * @var list<string>
     */
    private const array TABLES = [
        'billing_webhook_effect_runs',
        'billing_webhook_events',
    ];

    public function up(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $prefix = $connection->getTablePrefix();
        $grammar = $connection->getQueryGrammar();
        // The zone Eloquent wrote its timestamps in: the framework sets PHP's default zone from app.timezone.
        $applicationZone = date_default_timezone_get();

        /** @var array<string, list<string>> $changes the ALTER COLUMN clauses of each table */
        $changes = [];

        foreach ($connection->select(
            'select table_name, column_name, datetime_precision from information_schema.columns '
            .'where table_schema = current_schema() and table_name like ? and data_type = ? '
            .'order by table_name, ordinal_position',
            [$prefix.'billing\\_webhook\\_%', 'timestamp without time zone'],
        ) as $row) {
            $column = (array) $row;
            $table = substr(is_string($column['table_name'] ?? null) ? $column['table_name'] : '', strlen($prefix));

            if (! in_array($table, self::TABLES, true)) {
                continue;
            }

            $name = is_string($column['column_name'] ?? null) ? $column['column_name'] : '';
            $precision = is_numeric($column['datetime_precision'] ?? null) ? (int) $column['datetime_precision'] : 0;

            $changes[$table][] = sprintf(
                'alter column %s type timestamp(%d) with time zone%s',
                $grammar->wrap($name),
                $precision,
                $applicationZone === 'UTC' ? '' : ' using '.$grammar->wrap($name).' at time zone '.$connection->escape($applicationZone),
            );
        }

        foreach ($changes as $table => $clauses) {
            $connection->transaction(function () use ($connection, $grammar, $table, $clauses): void {
                $connection->statement("SET LOCAL lock_timeout = '3s'");
                $connection->statement('SET LOCAL statement_timeout = 0');
                // The session in UTC, so a UTC value is read and written as it was stored. Each table is rewritten
                // once: the precision every column keeps adds a length coercion PostgreSQL cannot prove a no-op.
                $connection->statement("SET LOCAL TIME ZONE 'UTC'");
                $connection->statement('alter table '.$grammar->wrapTable($table).' '.implode(', ', $clauses));
            });
        }
    }

    public function down(): void
    {
        // Nothing to turn back here: see the head of this file.
    }
};
