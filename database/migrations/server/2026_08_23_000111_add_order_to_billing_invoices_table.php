<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order a locally issued invoice states.
 *
 * A provider-driven driver has nothing to put here: Stripe issues the invoice and the package copies it,
 * keyed by `provider_id`. A local engine has no such document — it has an order it just collected money
 * for, and the invoice is raised FROM that. Without this column the two were connected by nothing, so
 * nothing could answer "has this order been invoiced" except a guess.
 *
 * **Unique, and that is the idempotency.** A cycle can be processed more than once — a retried queue job,
 * an operator running the command by hand — and each attempt that got as far as collecting would raise
 * another invoice for the same money. Invoice numbers are gapless and immutable by design, so a duplicate
 * is not something to clean up afterwards: it is a numbered document stating a charge that happened once.
 * The constraint makes the second attempt lose the insert rather than mint it.
 *
 * Nullable because most rows never have one: every invoice copied from a provider, and every marketplace
 * document, which is raised from a charge rather than from a subscription cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('order_id')->nullable()->after('provider_id');
            $table->unique('order_id', 'billing_invoices_order_unique');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropUnique('billing_invoices_order_unique');
            $table->dropColumn('order_id');
        });
    }
};
