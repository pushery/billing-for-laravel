<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\MigrationLocks;

/**
 * Whether a routed sale is a supply of goods the platform is treated as making itself, and the consignment that
 * decided it, frozen on the sale's row.
 *
 * A sale is classified once, before the money moves, and never moved between the two paragraphs of Art. 14a VAT
 * Directive afterwards. Every later reader takes the answer from here: the buyer's document of a hosted purchase,
 * which is written after the provider reports the payment, the correction a refund owes, and the seller's
 * settlement. Re-deriving it from the catalog then would ask a catalog that may have changed since the sale.
 *
 * `deemed_supply` names the paragraph, null on every sale that is no deemed supply and on rows written before it
 * was recorded. `goods_consignment` keeps the consignment the lane passed for a sale of goods, as JSON, so the tax of
 * the platform's supply can be decided from the same facts the classification was made from. Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->string('deemed_supply', 32)->nullable()->after('seller_posture');
            $table->json('goods_consignment')->nullable()->after('deemed_supply');
        });
    }

    public function down(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->dropColumn(['deemed_supply', 'goods_consignment']);
        });
    }
};
