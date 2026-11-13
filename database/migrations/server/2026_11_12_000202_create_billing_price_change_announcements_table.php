<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * That a subscription was told of a declared price change, and from which day the new price holds for it.
 *
 * One row per change and subscription, written when the announcement went out. `price_change` is the key the change is
 * declared under in `billing.price_changes.changes`. `effective_on` is the day the announcement named: the change's own,
 * or a later one where the announcement reached the subscription late, so the notice still runs in full. The cycle that
 * begins on or after that day is the first one billed at the new price.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->boundTheLock();

        try {
            Schema::create('billing_price_change_announcements', function (Blueprint $table): void {
                $table->id();
                // Named, because the name derived from table and column runs to 66 characters and MySQL takes 64.
                $table->foreignId('billing_subscription_id')
                    ->constrained('billing_subscriptions', indexName: 'billing_price_change_announcements_subscription_foreign')
                    ->cascadeOnDelete();
                $table->string('price_change');
                $table->dateTimeTz('announced_at');
                $table->date('effective_on');
                // With their zone on PostgreSQL and as a DATETIME on MySQL, like every moment of the package's tables.
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();

                $table->unique(['price_change', 'billing_subscription_id'], 'billing_price_change_announcements_unique');
                // The foreign key's own index, which PostgreSQL does not create by itself: the cycle reads the
                // announcements of its subscription through this column.
                $table->index('billing_subscription_id', 'billing_price_change_announcements_subscription_index');
            });
        } finally {
            $this->releaseTheBound();
        }
    }

    public function down(): void
    {
        $this->boundTheLock();

        try {
            Schema::dropIfExists('billing_price_change_announcements');
        } finally {
            $this->releaseTheBound();
        }
    }

    /**
     * Bounds the wait for the lock the foreign key takes on billing_subscriptions, and how long a statement may hold it.
     *
     * Creating or dropping a foreign key locks the table it references against writes. A migration that waits for that
     * lock queues every later query on the table behind it, so it gives up after three seconds instead and can be run
     * again. On PostgreSQL both bounds end with the migration's transaction.
     */
    private function boundTheLock(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement("SET LOCAL lock_timeout = '3s'");
            $connection->statement("SET LOCAL statement_timeout = '30s'");
        }

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $connection->statement('SET SESSION lock_wait_timeout = 3');
        }
    }

    /** MySQL has no setting that ends with a transaction, so the session gets its own bound back for what runs next. */
    private function releaseTheBound(): void
    {
        $connection = Schema::getConnection();

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $connection->statement('SET SESSION lock_wait_timeout = DEFAULT');
        }
    }
};
