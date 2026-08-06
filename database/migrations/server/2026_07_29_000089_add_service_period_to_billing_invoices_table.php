<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The period a document's supply actually covers — EN 16931's BG-14, at the document level.
 *
 * ## Why the document needs it when the lines already carry one
 *
 * A line's period says which months THAT line is for. The document's says which months the document is
 * for, and the two are not the same statement: a reader — an auditor, a tax authority, an accounts-payable
 * system — asks the second question first, and answering it by scanning the lines means every reader has to
 * agree on how to reduce a set of line periods to one. They will not. So it is recorded once, by the party
 * that knows.
 *
 * It also gives the period a place on documents that have no lines to carry it, which corrections and
 * settlement documents routinely are.
 *
 * ## Dates, not timestamps
 *
 * A service period is stated in whole days on every document format that carries it. A time of day would
 * make two documents for the same month differ by when they happened to be issued, and would invite a
 * timezone into a field that has no instant in it.
 *
 * ## Nullable, and that is the neutrality
 *
 * Every existing row has no stated period, which is exactly what null means here. A document that states
 * none renders precisely as it does today — the columns are additive and empty, so nothing about a
 * single-sale install changes by their existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->date('service_period_start')->nullable()->after('settlement_period');
            $table->date('service_period_end')->nullable()->after('service_period_start');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn(['service_period_start', 'service_period_end']);
        });
    }
};
