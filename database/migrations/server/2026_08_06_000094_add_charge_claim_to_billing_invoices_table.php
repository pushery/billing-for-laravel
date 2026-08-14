<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One FIRST document per owner, series and settled charge — the period index's twin for one-off sales.
 *
 * ## The hole this closes
 *
 * `billing_invoices_owner_series_period_unique` protects everything that carries a period. A one-off
 * purchase carries none, and its path avoids duplicates by reading whether a document already names the
 * charge and writing one if it does not. That is check-then-act again, and payment events are redelivered
 * — two deliveries arriving together both find nothing and both write. The second document draws its own
 * number from a running series, so a redelivery becomes a numbered document a return then counts twice.
 *
 * ## Why the obvious key would have been a regression, and what makes this one exact
 *
 * The obvious key — owner, series and the charge reference — refuses documents that MUST exist. A buyer
 * who took a short receipt may ask for a full invoice: that is a second document over the same sale, same
 * owner, same series, same reference, on purpose. A correction copies the reference for the same reason,
 * and there can be several of them.
 *
 * So the key is not the reference itself but the reference AS CLAIMED BY THE SALE'S FIRST DOCUMENT, and
 * the claim is derived to mirror the lookup it protects, condition for condition:
 *
 * - **Null on a reissue and on every correction.** A distinction this package already makes and already
 *   relies on: `SettlementCorrectionIssuer` finds an original with `whereNull('credited_invoice_id')`, and
 *   `InvoiceRecord::isReissue()` reads `reissue_of_invoice_id`.
 * - **Null on any document that names a period.** Those are recognized BY the period and guarded by the
 *   index above. A prepaid term is twelve monthly documents over ONE charge, and claiming the charge would
 *   refuse eleven of them.
 * - **Qualified by the provider**, because the lookup is. Two drivers can mint the same id for different
 *   money; a key on the bare reference would refuse the second installation's real document. The provider
 *   is part of the key even when it is empty, so a document that recorded none is still constrained rather
 *   than dropping out of the index entirely.
 *
 * Nulls are the mechanism, not a loophole, exactly as in the period index: every engine here treats them
 * as distinct in a unique index, so the documents that are allowed to repeat never collide, and only the
 * one document that must be unique is constrained.
 *
 * ## What this can refuse on an existing installation
 *
 * Nothing that already exists. The column is null on every row written before this ran, so no historical
 * pair can collide — and it needs no backfill to be correct, because a redelivery of an old charge still
 * finds its document through the reference lookup, which is untouched. The constraint governs documents
 * written from here on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('charge_claim_key')->nullable()->after('settled_charge_reference');

            $table->unique(
                ['owner_type', 'owner_id', 'document_series', 'charge_claim_key'],
                'billing_invoices_owner_series_charge_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropUnique('billing_invoices_owner_series_charge_unique');
            $table->dropColumn('charge_claim_key');
        });
    }
};
