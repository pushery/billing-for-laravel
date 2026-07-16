<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Prepares a host app to use the package: publishes the config and generates the owner-columns
 * migration (the tier column plus Cashier's customer columns) for the billable's own table. The
 * package ships the server-side billing tables and loads them automatically, but the columns that live
 * on the CONSUMER'S table — the denormalized tier the hot path reads, and `stripe_id` — no package
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
     * A configured column name, or its default. The generated migration MUST agree with the config the
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
     * trial_ends_at) is included ONLY when it does not already exist on the table at install time — meaning
     * THIS migration is the one that creates it. If it already exists it belongs to Cashier's own migration,
     * and dropping it on a routine migrate:rollback would sever every customer from Stripe (webhooks lose
     * their owner, the next charge creates a duplicate customer). When in doubt, the column is kept.
     *
     * @return list<string>
     */
    private function droppableColumns(string $table, string $tierColumn, string $customerColumn): array
    {
        $drop = [$tierColumn];
        $tableExists = Schema::hasTable($table);

        foreach ([$customerColumn, 'pm_type', 'pm_last_four', 'trial_ends_at'] as $column) {
            if (! $tableExists || ! Schema::hasColumn($table, $column)) {
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
        $path = database_path("migrations/{$name}");

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $this->migration($table, $tierColumn, $customerColumn, $dropColumns));

        return $path;
    }

    /**
     * The migration body. Every column is guarded by hasColumn(), so it is safe to run after Cashier's
     * own customer migration (which adds stripe_id / pm_type / pm_last_four / trial_ends_at) — it fills
     * in only the tier column, or all of them on an app that never ran Cashier's.
     *
     * The tier and customer column names come from the config the package READS at runtime, never from a
     * literal: a consumer who renamed either one and got a migration for the default name would end up
     * with a column nothing writes and a package looking at a column that does not exist.
     */
    /**
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
                    if (! Schema::hasColumn('{$table}', '{$tierColumn}')) {
                        \$table->string('{$tierColumn}')->nullable();
                    }
                    if (! Schema::hasColumn('{$table}', '{$customerColumn}')) {
                        \$table->string('{$customerColumn}')->nullable()->index();
                    }
                    if (! Schema::hasColumn('{$table}', 'pm_type')) {
                        \$table->string('pm_type')->nullable();
                    }
                    if (! Schema::hasColumn('{$table}', 'pm_last_four')) {
                        \$table->string('pm_last_four', 4)->nullable();
                    }
                    if (! Schema::hasColumn('{$table}', 'trial_ends_at')) {
                        \$table->timestamp('trial_ends_at')->nullable();
                    }
                });
            }

            public function down(): void
            {
                // Only the columns this migration is responsible for creating, each guarded so a rollback
                // never errors on an already-absent column — and Cashier's own columns are never dropped.
                foreach ([{$dropList}] as \$column) {
                    if (! Schema::hasColumn('{$table}', \$column)) {
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
        };

        PHP;
    }
}
