<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one order took off a discount allowance.
 *
 * One row per allowance and order. A cycle that is priced again, as a dunning retry is, rewrites the row of its own
 * order, so the amount is always what the order's discount line states. A use counts against the allowance unless its
 * order failed: a cycle that was never collected gives back what it would have taken.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->boundTheLock();

        try {
            Schema::create('billing_discount_allowance_uses', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('discount_allowance_id')->constrained('billing_discount_allowances')->cascadeOnDelete();
                $table->foreignId('order_id')->constrained('billing_orders')->cascadeOnDelete();
                $table->unsignedBigInteger('amount_minor');
                // With their zone on PostgreSQL and as a DATETIME on MySQL, like every moment of the package's tables.
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();

                $table->unique(['discount_allowance_id', 'order_id'], 'billing_discount_allowance_uses_order_unique');
                // The foreign key's own index, which PostgreSQL does not create by itself: a retry replaces the uses of
                // its order, and deleting an order reaches its uses through this column.
                $table->index('order_id', 'billing_discount_allowance_uses_order_index');
            });
        } finally {
            $this->releaseTheBound();
        }
    }

    public function down(): void
    {
        $this->boundTheLock();

        try {
            Schema::dropIfExists('billing_discount_allowance_uses');
        } finally {
            $this->releaseTheBound();
        }
    }

    /**
     * Bounds the wait for the lock the foreign keys take on billing_discount_allowances and billing_orders, and how long
     * a statement may hold it.
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
