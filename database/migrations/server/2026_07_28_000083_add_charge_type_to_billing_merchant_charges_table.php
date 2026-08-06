<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which lane a routed sale took, frozen onto the row.
 *
 * ## Why it has to be on the row and not derived
 *
 * The two lanes reverse in completely different ways. On a destination charge the provider created the
 * transfer as part of the payment, so the refund can unwind both together. On a separate transfer the money
 * moved in its own call, and refunding the payment does not touch it — the reversal is a second call with an
 * explicitly calculated amount.
 *
 * A refund therefore has to know which one it is looking at. The only other source is today's configuration,
 * and that is exactly the mistake the commission terms beside this column were frozen to avoid: an operator
 * who changes `billing.marketplace.charge_type` would have every OLD sale reversed as though it had been
 * made under the new lane. On a destination charge read as a separate transfer, nothing reverses and the
 * merchant keeps a share of a refunded sale. Read the other way, the refund carries a `reverse_transfer`
 * flag that quietly does nothing, and the failure looks like success.
 *
 * ## Nullable, and null means "written before this was recorded"
 *
 * Not a default. Guessing a lane for an old row is the same error as reading it off today's config, one step
 * removed — so a row that never carried the answer says so, and the caller decides what to do about a sale
 * it cannot classify. That is the shape `fee_bps` already uses, and for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->string('charge_type', 32)->nullable()->after('transfer_reference');
        });
    }

    public function down(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->dropColumn('charge_type');
        });
    }
};
