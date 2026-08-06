<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment that was routed: what the buyer paid, what the platform kept, what the merchant received.
 *
 * Without this row nothing in the package knows a charge was routed at all. The support path would take
 * the non-reversing branch on a refund, silently, because there is nothing to tell it otherwise — and the
 * money would already be with the merchant.
 *
 * THREE cumulative sums, not one, and that is the whole design. The moment a platform keeps its commission
 * on a refund — a normal policy, the work was done — the buyer gets 1000 back while only 900 is clawed
 * back from the merchant, and the two totals diverge permanently. A single column cannot carry three
 * monotonic sums that move at different speeds; keying a clawback off the refunded total would skip the
 * second partial refund without a word.
 *
 * `transfer_ref` is null until settlement, which is a state and not a gap: a routed charge that has not
 * settled is never credited to a merchant, and a 3-D Secure step (routine under PSD2) leaves it pending
 * rather than failed.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_merchant_charges', function (Blueprint $table): void {
            $table->id();
            $table->morphs('merchant');
            $table->string('provider');

            // The provider's own identity for the payment and for the transfer that carried the merchant's
            // share. The charge reference is unique per provider: a redelivered webhook must converge on
            // the row it already wrote rather than adding a second one.
            $table->string('charge_reference');
            $table->string('transfer_reference')->nullable();

            // What the buyer paid, what the platform kept, what the merchant is owed. Stored as three
            // values rather than two plus arithmetic, because the split was decided once at capture and
            // re-deriving it later would read today's fee policy into yesterday's payment.
            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('fee_minor');
            $table->unsignedBigInteger('net_minor');
            $table->string('currency', 3);

            $table->string('settlement_state')->default('pending');
            $table->timestamp('settled_at')->nullable();

            // The three sums. Each is a cap for a different reversal, and each moves on its own.
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->unsignedBigInteger('transfer_reversed_minor')->default(0);
            $table->unsignedBigInteger('fee_refunded_minor')->default(0);

            // The merchant's erasure marker, on the same axis as the rest of a merchant's records: a
            // routed charge is a financial record that outlives the person named on it.
            $table->timestamp('merchant_erased_at')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'charge_reference'], 'billing_merchant_charges_reference_unique');

            // The read path of the earnings journal and of every clawback: everything a merchant was
            // routed, newest first.
            $table->index(['merchant_type', 'merchant_id', 'settlement_state'], 'billing_merchant_charges_merchant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_merchant_charges');
    }
};
