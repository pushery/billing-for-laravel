<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rate a settled supply is taxable at, whoever supplies it.
 *
 * `tax_rate_bps` is the rate a document STATES, and a settlement often states none: a small business
 * charges no tax, and a reverse-charged supply leaves the tax to the recipient. The supply still has a
 * rate. The recipient self-assesses a reverse charge at it, and the DATEV export picks the reduced
 * reverse-charge account from it.
 *
 * Nullable because only the self-billing engine writes it, and every row written before this column
 * existed has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->unsignedInteger('supply_rate_bps')->nullable()->after('tax_rate_bps');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('supply_rate_bps');
        });
    }
};
