<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fan-side gross of the transaction a self-billed settlement settles — frozen onto the Gutschrift so the
 * DATEV booking chain can post the fan sale it is the counterpart of.
 *
 * A commission-chain transaction is two fictional supplies of ONE sale: the platform sells to the fan and
 * buys from the creator. The DATEV chain books both legs plus the payout, and the fan leg (money-in against
 * revenue) needs the fan gross (119.00 on the worked example). The Gutschrift is the record that ties the two
 * legs together — it settles exactly that fan transaction — so the fan gross is frozen here, at issue, when
 * the engine still holds it, rather than re-derived later from a rate that can change.
 *
 * Null on every non-settlement row (a fan invoice, a single-seller invoice), so the export's chain path only
 * fires for a settlement that carries it and every other row is unchanged. Scalar, frozen in the scalar loop
 * of InvoiceRecord::booted() — a settled transaction's fan gross does not move. Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('fan_gross_minor')->nullable()->after('invoice_effect_revoked_channel');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('fan_gross_minor');
        });
    }
};
