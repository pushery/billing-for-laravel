<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The commission terms a routed sale was made under, frozen onto the charge.
 *
 * ## Why the three amounts are not enough
 *
 * The row already stores gross, fee and net, and from those a FULL clawback is exactly derivable: everything
 * the merchant and the platform still hold goes back. A PARTIAL one is not. Recomputing a proportional share
 * is the wrong figure whenever the fee has a flat component -- a 100.00 sale at 10% plus 1.00 flat pays out
 * 89.00, and half of it refunded leaves a 50.00 sale that would have paid out 44.00, so 45.00 comes back and
 * not 44.50. Getting that right needs the rate and the flat part, and both are gone once the numbers are
 * added up.
 *
 * ## Why it cannot be read from the configuration instead
 *
 * The configuration holds today's terms. A platform that raises its commission would then have every OLD
 * sale clawed back at the NEW rate, and nothing on the row would say so -- both figures look entirely
 * plausible, and only a merchant reconciling by hand would ever notice.
 *
 * This is the same frozen-column rule the tax snapshot follows, for the same reason: a fact a document was
 * made under lives on the document.
 *
 * ## Nullable, and deliberately not backfilled
 *
 * Rows written before this migration do not have the terms, and there is no honest value to give them: a
 * backfill from the current configuration would be exactly the guess-as-fact this column exists to prevent.
 * So an old row answers "I do not know" and the caller has to see it, rather than being handed a plausible
 * number.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->unsignedInteger('fee_bps')->nullable()->after('fee_minor');
            $table->unsignedBigInteger('fee_flat_minor')->nullable()->after('fee_bps');
        });
    }

    public function down(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->dropColumn(['fee_bps', 'fee_flat_minor']);
        });
    }
};
