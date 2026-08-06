<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which kind of document a buyer receipt is, decided when it was issued.
 *
 * The tier is a decision about a SINGLE document, taken from that document's own gross — a monthly
 * subscription stays a short receipt with no buyer identity, and the same contract billed once a year
 * crosses the threshold and pulls the buyer's data in. Recomputing the tier later from the same figure would
 * usually agree; recomputing it after the threshold moved would not, and the document would then be rendered
 * as something it never was.
 *
 * It also decides what the rendered document may say. A short receipt shows the gross with its rate in one
 * sum and names nobody; a full invoice shows the split and the parties. Without the tier on the row, the
 * renderer has to guess from the amount — which is the same recomputation, one layer further out.
 *
 * Null on every document that is not a buyer receipt, which is every document a single-seller install writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('receipt_tier', 32)->nullable()->after('document_series');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('receipt_tier');
        });
    }
};
