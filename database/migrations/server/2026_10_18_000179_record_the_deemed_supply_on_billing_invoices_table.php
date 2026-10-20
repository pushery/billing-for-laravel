<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\MigrationLocks;

/**
 * The paragraph that makes the platform the supplier of the goods a document is about, and where the goods were
 * when the transport began, frozen on the issued document.
 *
 * A deemed supply has two documents: the seller's supply to the platform and the platform's supply to the buyer.
 * Both write these columns from the same classification, so the two cannot disagree about which supply they
 * describe, and a correction copies them. `goods_located_in` is the member state where the transport begins for
 * goods already in the Union, an ISO 3166 code: the place of the seller's exempt supply and the start of the
 * transport the records of Art. 63c(1)(k) Implementing Regulation 282/2011 name. Null on every other document.
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('deemed_supply', 32)->nullable()->after('ioss');
            $table->char('goods_located_in', 2)->nullable()->after('deemed_supply');
        });
    }

    public function down(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn(['deemed_supply', 'goods_located_in']);
        });
    }
};
