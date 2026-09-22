<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payment behind a subscription cycle, beside the invoice the cycle is recorded under.
 *
 * A one-off sale is recorded under its payment, so anything that names the payment finds its row. A cycle is
 * recorded under its invoice, one row per cycle, and a dispute names only the payment: `charge.dispute.*`
 * carries the payment intent and the charge, never the invoice. With nothing else on the row, a lost dispute
 * over a routed cycle finds no row at all and is booked as an ordinary add-on reversal, which reaches none of
 * what a chargeback owes a routed sale: the provider's fee, the corrected chain and the merchant's share.
 *
 * The writer of a cycle already knows the payment, because reading the commission starts from it on both
 * lanes. So the column is filled where the row is written and costs no provider call of its own.
 *
 * Nullable, and not backfilled. Null means the row was written before this was recorded, or that the row is a
 * one-off sale whose reference already is the payment. Filling old rows would mean asking the provider about
 * every past invoice from inside a migration.
 *
 * Server-only, reversible, additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->string('payment_reference')->nullable()->after('charge_reference');
            $table->index(['provider', 'payment_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->dropIndex(['provider', 'payment_reference']);
            $table->dropColumn('payment_reference');
        });
    }
};
