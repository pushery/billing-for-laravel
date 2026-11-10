<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\MigrationLocks;

/**
 * The index an application finds the deliveries about one payment of an account by, under the payment's other id.
 *
 * The deliveries table is the one the package writes on every webhook, so the index is built without stopping those
 * writes. On PostgreSQL that is `CREATE INDEX CONCURRENTLY`, which cannot run inside a transaction: this migration runs
 * outside the one Laravel would open, and is apart from the one that adds the column, which needs its transaction to
 * bound its lock. A concurrent build that fails leaves an invalid index of the same name behind, which the planner never
 * reads and which `IF NOT EXISTS` would take for the finished one, so whatever stands under the name goes first and
 * running the migration again builds the index. MySQL adds an index in place while writes go on, and the wait for the table's metadata lock
 * is bounded. SQLite builds it plainly.
 */
return new class extends Migration
{
    private const string INDEX = 'billing_webhook_events_payment_alias_idx';

    /** @var list<string> */
    private const array COLUMNS = ['provider', 'account_reference', 'payment_alias'];

    /** PostgreSQL refuses a concurrent build inside a transaction. */
    public $withinTransaction = false;

    public function up(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $grammar = $connection->getQueryGrammar();

            // On a first run nothing stands under the name, and the drop changes nothing.
            $connection->statement('drop index concurrently if exists '.$grammar->wrap(self::INDEX));

            $connection->statement(sprintf(
                'create index concurrently if not exists %s on %s (%s)',
                $grammar->wrap(self::INDEX),
                $grammar->wrapTable('billing_webhook_events'),
                $grammar->columnize(self::COLUMNS),
            ));

            return;
        }

        MigrationLocks::bound();

        Schema::table('billing_webhook_events', function (Blueprint $table): void {
            $table->index(self::COLUMNS, self::INDEX);
        });
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement('drop index concurrently if exists '.$connection->getQueryGrammar()->wrap(self::INDEX));

            return;
        }

        MigrationLocks::bound();

        Schema::table('billing_webhook_events', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
