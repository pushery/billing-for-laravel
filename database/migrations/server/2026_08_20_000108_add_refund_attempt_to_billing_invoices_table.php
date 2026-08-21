<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which reversal a correcting document documents.
 *
 * A correction states a reduction. What actually moved is recorded on the attempt row the provider
 * answered, and until now nothing joined the two: a reader holding a correction could see how much came
 * off and not which confirmation took it off, and a reader holding an attempt could not find the document
 * that states its consequence. Both rows existed, both were correct, and the sentence connecting them was
 * carried only by the fact that they were written in the same call.
 *
 * ## Why the join has to be stored rather than inferred
 *
 * Reference and date look like enough to match on and are not. Several confirmations can land against one
 * charge, each capped against what was still refundable at that moment, so a correction and an attempt can
 * agree on the charge and disagree on which reversal they describe. Matching on the AMOUNT is worse:
 * `ClawbackCalculator` floors at zero and `completeRefund()` caps every sum against its own ceiling, so
 * two attempts can produce identical figures for different money. A stored id is the only answer that
 * stays right when a sale is refunded twice.
 *
 * ## Why nullable, and why null is an answer rather than a gap
 *
 * Null means no reversal row stands behind this correction — which is the ORDINARY case on two of the
 * three paths that correct a chain. A prepaid term cancellation computes an unused portion and issues the
 * documents without opening an attempt at all, and the chargeback effect corrects the chain in a different
 * unit of work from the one that reverses the merchant's share. Only the admin refund path holds the
 * attempt at the moment it corrects.
 *
 * Null therefore does NOT mean "the reversal moved nothing" — that is `fee_refund_minor` on an attempt
 * that exists. The two are different facts, and a default of zero would state the second where only the
 * first is true; the rows it misstated would be exactly the ones somebody later goes looking for. It is
 * the same distinction `transfer_reversal_short_minor` is documented against on the attempt itself.
 *
 * ## What this deliberately does NOT do
 *
 * It does not change where any counter places any figure. The DAC7 fee figure still runs on the money's
 * clock and the value figure on the document's, and this column does not by itself bring them together —
 * the divergence that `ThreeCountersOneSaleTest` pins is a sale with no refund in it at all, which no
 * link from a correction can reach. Picking one clock for both figures is a reporting decision with a tax
 * consequence and it is not made here.
 *
 * No index. Nothing queries by this column yet, and an index whose only justification is a query somebody
 * might write later is a write cost paid for a read that does not happen.
 *
 * Server-only, reversible, additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('refund_attempt_id')->nullable()->after('reissue_of_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('refund_attempt_id');
        });
    }
};
