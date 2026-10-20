<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\MigrationLocks;

/**
 * Where a routed sale's goods were sent, or where its buyer was, as an ISO 3166 code, on the sale's row.
 *
 * A platform keeps records of the supplies it facilitates without being treated as their supplier (Art. 242a VAT
 * Directive), and for goods they name the place where the dispatch or transport ends (Art. 54c(2) Implementing
 * Regulation 282/2011). Under intermediation the platform issues no document of the seller's sale, so the row is
 * the one place that can keep it. The direct lane writes it with the sale, a hosted purchase once the provider
 * reports the address the buyer entered. Null on rows written before it was recorded. Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->char('destination_country', 2)->nullable()->after('goods_consignment');
        });
    }

    public function down(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->dropColumn('destination_country');
        });
    }
};
