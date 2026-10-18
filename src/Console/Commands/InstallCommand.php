<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\TableLookup;

/**
 * Prepares a host app to use the package: publishes the config and generates the owner-columns
 * migration (the tier column plus Cashier's customer columns) for the billable's own table. The
 * package ships the server-side billing tables and loads them automatically, but the columns that live
 * on the consumer's table — the denormalized tier the hot path reads, and `stripe_id` — no package
 * migration can create without knowing which table. This command is where a fresh install stops
 * rendering "Free" while every plan-sync webhook dies at "column not found".
 */
final class InstallCommand extends Command
{
    protected $signature = 'billing:install
        {--table= : The owner table to add the billing columns to (defaults to the configured customer model, else "users")}
        {--no-config : Skip publishing the config files}';

    protected $description = 'Publish the billing config and generate the owner-columns migration.';

    public function handle(Repository $config, Filesystem $files): int
    {
        if ($this->option('no-config') !== true) {
            $this->callSilent('vendor:publish', ['--tag' => 'billing-config']);
            $this->components->info('Published the billing config (config/billing.php, account.php, license.php).');
        }

        $table = $this->ownerTable($config);
        $tierColumn = $this->column($config, 'tier_column', 'plan');
        $customerColumn = $this->column($config, 'customer.column', 'stripe_id');

        // The names go into schema calls, a file name and generated PHP, and nothing on that way quotes them: a
        // quote in one wrote a migration that does not parse. Refused before the database is asked or a file is
        // written.
        $unusable = $this->unusableName($table, $tierColumn, $customerColumn);

        if ($unusable !== null) {
            $this->components->error($unusable);

            return self::FAILURE;
        }

        $path = $this->writeMigration($files, $table, $tierColumn, $customerColumn, $this->droppableColumns($table, $tierColumn, $customerColumn));

        $this->components->info("Wrote the owner-columns migration for '{$table}' to {$path}.");

        $this->components->bulletList([
            'Point billing.customer.model at your billable model (a webhook cannot find its owner without it).',
            'Set your Stripe keys — STRIPE_KEY, STRIPE_SECRET and STRIPE_WEBHOOK_SECRET.',
            'Run: php artisan migrate',
            'Migrating from Cashier? Then run: php artisan billing:sync — it imports every existing subscriber so your paying customers are not left showing Free.',
            'Schedule the package commands (the service provider already registers billing:run hourly and billing:usage:flush every minute).',
        ]);

        return self::SUCCESS;
    }

    /** The owner table: the option, else the configured customer model's table, else "users". */
    private function ownerTable(Repository $config): string
    {
        $option = $this->option('table');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        $model = $config->get('billing.customer.model');

        if (is_string($model) && is_a($model, Model::class, true)) {
            return (new $model)->getTable();
        }

        return 'users';
    }

    /**
     * What is wrong with a name the migration is built from, or null when each one can be written as it is.
     *
     * Letters, digits and underscores, not starting with a digit, at most 64 characters, the identifier limit
     * MySQL sets. A schema-qualified table is outside it: the generated migration is then written by hand.
     */
    private function unusableName(string $table, string $tierColumn, string $customerColumn): ?string
    {
        foreach (['owner table' => $table, 'column in billing.tier_column' => $tierColumn, 'column in billing.customer.column' => $customerColumn] as $what => $name) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name) !== 1) {
                return "The {$what}, '{$name}', is not a name the generated migration can carry: use letters, digits and underscores, not starting with a digit, at most 64 characters.";
            }
        }

        return null;
    }

    /**
     * A configured column name, or its default. The generated migration must agree with the config the
     * package reads at runtime: a consumer who renames the tier or customer column and then gets a
     * migration for the default names ends up with two columns, one of which nothing ever writes.
     */
    private function column(Repository $config, string $key, string $default): string
    {
        $column = $config->get('billing.'.$key, $default);

        return is_string($column) && $column !== '' ? $column : $default;
    }

    /**
     * The columns the generated down() may safely drop. The tier column is the package's own, so it is
     * always reversible. A Cashier-shaped column (the customer reference, pm_type, pm_last_four,
     * trial_ends_at) is included only when it does not already exist on the table at install time — meaning
     * this migration is the one that creates it. If it already exists it belongs to Cashier's own migration,
     * and dropping it on a routine migrate:rollback would sever every customer from Stripe (webhooks lose
     * their owner, the next charge creates a duplicate customer). When in doubt, the column is kept.
     *
     * @return list<string>
     */
    private function droppableColumns(string $table, string $tierColumn, string $customerColumn): array
    {
        $drop = [$tierColumn];
        $tableExists = TableLookup::exists($table);

        foreach ([$customerColumn, 'pm_type', 'pm_last_four', 'trial_ends_at'] as $column) {
            if (! $tableExists || ! Schema::hasColumn(TableLookup::qualified($table), $column)) {
                $drop[] = $column;
            }
        }

        return $drop;
    }

    /**
     * Generate the migration file for the owner table and return its path.
     *
     * @param  list<string>  $dropColumns
     */
    private function writeMigration(Filesystem $files, string $table, string $tierColumn, string $customerColumn, array $dropColumns): string
    {
        $name = Carbon::now()->format('Y_m_d_His')."_add_billing_columns_to_{$table}_table.php";
        $path = $this->laravel->databasePath("migrations/{$name}");

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $this->migration($table, $tierColumn, $customerColumn, $dropColumns));

        return $path;
    }

    /**
     * The migration body. Every column is guarded by its own lacks(), so it is safe to run after Cashier's
     * own customer migration (which adds stripe_id / pm_type / pm_last_four / trial_ends_at) — it fills
     * in only the tier column, or all of them on an app that never ran Cashier's.
     *
     * The tier and customer column names come from the config the package reads at runtime, never from a
     * literal: a consumer who renamed either one and got a migration for the default name would end up
     * with a column nothing writes and a package looking at a column that does not exist.
     *
     * lacks() looks the column up where a query finds the table, which on PostgreSQL is along the
     * search_path. It is written into the migration rather than calling a class of this package,
     * because the file belongs to the application from the moment it is written.
     *
     * @param  list<string>  $dropColumns
     */
    private function migration(string $table, string $tierColumn, string $customerColumn, array $dropColumns): string
    {
        $dropList = implode(', ', array_map(static fn (string $column): string => "'{$column}'", $dropColumns));

        return <<<PHP
        <?php

        declare(strict_types=1);

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('{$table}', function (Blueprint \$table): void {
                    if (\$this->lacks('{$tierColumn}')) {
                        \$table->string('{$tierColumn}')->nullable();
                    }
                    if (\$this->lacks('{$customerColumn}')) {
                        \$table->string('{$customerColumn}')->nullable()->index();
                    }
                    if (\$this->lacks('pm_type')) {
                        \$table->string('pm_type')->nullable();
                    }
                    if (\$this->lacks('pm_last_four')) {
                        \$table->string('pm_last_four', 4)->nullable();
                    }
                    if (\$this->lacks('trial_ends_at')) {
                        \$table->timestamp('trial_ends_at')->nullable();
                    }
                });
            }

            public function down(): void
            {
                // Only the columns this migration is responsible for creating, each guarded so a rollback
                // never errors on an already-absent column — and Cashier's own columns are never dropped.
                foreach ([{$dropList}] as \$column) {
                    if (\$this->lacks(\$column)) {
                        continue;
                    }

                    Schema::table('{$table}', function (Blueprint \$table) use (\$column): void {
                        // The customer column is indexed (up() added ->index()); SQLite refuses to drop an
                        // indexed column, so drop its index first. Only this migration's own customer column
                        // is ever in the drop list — a pre-existing Cashier column is excluded entirely.
                        if (\$column === '{$customerColumn}') {
                            \$table->dropIndex(['{$customerColumn}']);
                        }

                        \$table->dropColumn(\$column);
                    });
                }
            }

            /**
             * Whether '{$table}' lacks the column, looked up where a query on the connection finds the
             * table: on PostgreSQL along the search_path, which the schema builder alone does not walk.
             */
            private function lacks(string \$column): bool
            {
                \$connection = Schema::getConnection();
                \$table = '{$table}';

                if (\$connection->getDriverName() === 'pgsql') {
                    \$schema = \$connection->scalar(
                        'select n.nspname from pg_class c join pg_namespace n on n.oid = c.relnamespace where c.oid = to_regclass(?)',
                        [\$connection->getQueryGrammar()->wrapTable(\$table)],
                    );

                    \$table = is_string(\$schema) ? \$schema.'.'.\$table : \$table;
                }

                return ! Schema::hasColumn(\$table, \$column);
            }
        };

        PHP;
    }
}
