<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The buyer fee, frozen onto the sale it was charged on.
 *
 * ## Why it has to be stored and cannot be recomputed
 *
 * A withdrawal returns the fee that was CHARGED, and the only honest source for that figure is the sale.
 * Recomputing it from configuration at withdrawal time would price an old sale at today's rate, at today's
 * model, and at today's place of supply — three inputs an operator is free to change, none of which leaves
 * a trace saying it did. The result would be a plausible number, which is the worst kind of wrong here.
 *
 * That is the same rule the row already applies to the commission terms (`fee_bps`, `fee_flat_minor`,
 * `commission_tax_bps`) and for the same reason.
 *
 * ## Why all four columns and not just the gross
 *
 * The fee is the platform's own taxable supply. Its net and its tax are what a correcting document has to
 * carry, and the place is what decides the rate that applied — twenty countries share the euro, so the
 * currency cannot stand in for it. Storing only the gross would mean deriving the other three later from a
 * rate nobody recorded.
 *
 * ## Nullable, and the distinction it makes
 *
 * NULL means "no buyer fee on this sale" — the shipped default and the overwhelming majority of rows. It is
 * deliberately not zero: a zero-valued fee would be a supply of nought on a receipt where none happened, and
 * every reader counting the platform's own supplies would count it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->unsignedBigInteger('buyer_fee_gross_minor')->nullable()->after('commission_tax_bps');
            $table->unsignedBigInteger('buyer_fee_net_minor')->nullable()->after('buyer_fee_gross_minor');
            $table->unsignedBigInteger('buyer_fee_tax_minor')->nullable()->after('buyer_fee_net_minor');
            $table->string('buyer_fee_place_of_supply', 2)->nullable()->after('buyer_fee_tax_minor');

            // What has already gone back, so a retried withdrawal returns the fee once. NOT nullable and not
            // merged into `refunded_minor`: that counter is capped against the sale's gross, which the fee is
            // not part of, so a shared counter would either cap the fee away or inflate the sale's own cap.
            $table->unsignedBigInteger('buyer_fee_refunded_minor')->default(0)->after('buyer_fee_place_of_supply');
        });
    }

    public function down(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->dropColumn([
                'buyer_fee_gross_minor',
                'buyer_fee_net_minor',
                'buyer_fee_tax_minor',
                'buyer_fee_place_of_supply',
                'buyer_fee_refunded_minor',
            ]);
        });
    }
};
