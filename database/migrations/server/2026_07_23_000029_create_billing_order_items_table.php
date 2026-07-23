<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lines of a local order — what it bills and for how much. The order's total is the sum of these, so a
 * line can be positive (a charge) or negative (a discount or a credit-balance offset).
 *
 * Server-only, reversible. Keyed to its order, not to the owner: erasure and export reach it through the
 * order (OwnerScopedTables::CASCADED), and it drops with its order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('billing_orders')->cascadeOnDelete();
            $table->string('description');
            $table->bigInteger('unit_price_minor');
            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('total_minor');
            // The line carries its own currency so it is self-describing: a Money value needs a currency, and
            // reading it must not depend on the parent order being loaded. It always matches the order's.
            $table->char('currency', 3);
            // The line's tax rate in basis points (1900 = 19%), when the line carries tax. Nullable because a
            // discount or a credit line has none. Basis points, not a float — the money layer never touches a
            // float, and neither does its rate.
            $table->unsignedInteger('tax_bps')->nullable();
            $table->string('type');
            $table->json('metadata')->nullable();
            $table->timestamps();

            // The foreign key's index (Postgres does not create one for a FK by itself), and the order in
            // which a cycle's lines are read back.
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_order_items');
    }
};
