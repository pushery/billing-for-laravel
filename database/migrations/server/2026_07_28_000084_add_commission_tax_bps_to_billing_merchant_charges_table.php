<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHICH AMOUNT the commission was taken on, frozen onto the charge.
 *
 * ## Why a row cannot answer this from what it already stores
 *
 * The configuration says in as many words that the take rate is a NET rate — applied to the transaction's
 * net, not to what the buyer paid. The money path applied it to the payment. On the specification's own base
 * case, 119.00 at 19% VAT with a 10% take rate, that is 11.90 kept instead of 10.00: the platform's
 * commission on the buyer's tax, which is not the platform's money.
 *
 * The two bases coincide exactly when the fee is rate-only AND the creator's inbound rate equals the
 * outbound one — which is the case the golden test happens to run. A flat component, a small-business
 * creator, a reverse-charge creator or a cross-border rate each break the coincidence, and none of them
 * produce a wrong-looking number.
 *
 * Correcting the calculation is not enough on its own, because a partial clawback recomputes the commission
 * on what REMAINS of the sale. Without a stored rate it would have to reach for today's configuration, and
 * an old sale would come back at a base it was never made under. So the base is frozen here, next to the
 * terms it belongs with.
 *
 * ## Why the RATE and not the computed base amount
 *
 * The base amount is derivable from the gross and the rate, and a figure stored twice can disagree with
 * itself by a cent — with nothing downstream able to say which of the two the sale actually used. The rate
 * is also the only one of the two that still answers on a PARTIAL refund, where the base has to be
 * recomputed on the remainder rather than scaled.
 *
 * ## Nullable, and null is a real answer
 *
 * NULL means the commission was taken on the payment itself. That is what every row written before this
 * migration did, and it is the truth about those rows rather than a gap in them — they describe money that
 * actually moved that way. A backfill to the new base would rewrite history into something tidier and false.
 *
 * A new row always states its rate, including 0 for a tax-free sale. So null identifies exactly the rows
 * written under the old basis, and no new row can join them.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->unsignedInteger('commission_tax_bps')->nullable()->after('fee_flat_minor');
        });
    }

    public function down(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->dropColumn('commission_tax_bps');
        });
    }
};
