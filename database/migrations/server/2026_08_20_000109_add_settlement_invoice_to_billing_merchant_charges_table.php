<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which document settled this charge, for the run where nothing else can say.
 *
 * A per-transaction settlement records the answer on the DOCUMENT: it carries `settled_charge_reference`,
 * and every correction path finds its original through that column. A COLLECTIVE settlement cannot — it
 * settles a creator's whole month into one Ultimo-dated document whose transactions are lines, and neither
 * the header nor a line names a charge.
 *
 * So after a collective run the package held no link at all between a routed charge and the document that
 * settled it. A refund on such a sale therefore found no settlement to correct and issued nothing, while
 * the money ledger updated correctly and every guard stayed green — the exact shape of failure this area
 * keeps finding in itself, an absence read as an answer.
 *
 * ## Why on the charge rather than in the document's lines
 *
 * The lines are a JSON array the e-invoice renderers read, so a new key there changes a shipped document
 * format. The line schema also has to grow the frozen tax characteristics before a collective settlement
 * can be CORRECTED at all, and that shape is a separate, undecided question — changing the same schema
 * twice, once before that decision, is the more expensive order.
 *
 * A column here is queryable without a JSON search, which matters because this tree is proven on Postgres
 * and MySQL both and their JSON operators are not the same.
 *
 * ## Why nullable, and what null says
 *
 * Null means no collective run has claimed this charge: it was settled per transaction, or it has not been
 * settled yet, or the caller named no charge on the transaction. It does NOT mean the charge is unsettled —
 * `settlement_state` answers that, and the two are deliberately separate facts.
 *
 * No foreign key constraint, matching the tables around it: the charge and the document are written by
 * different engines on different schedules, and a hard constraint would make a document's removal fail
 * rather than leave a charge honestly pointing at nothing.
 *
 * Server-only, reversible, additive. Every existing row is null and no caller changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->unsignedBigInteger('settlement_invoice_id')->nullable()->after('settled_at');
            $table->index('settlement_invoice_id', 'billing_merchant_charges_settlement_invoice_index');
        });
    }

    public function down(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->dropIndex('billing_merchant_charges_settlement_invoice_index');
            $table->dropColumn('settlement_invoice_id');
        });
    }
};
