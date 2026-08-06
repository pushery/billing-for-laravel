<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per attempt to reverse money, written BEFORE the provider is called.
 *
 * This is what makes a locally started refund idempotent, and the reason it exists at all is that the
 * obvious alternative is unsafe. A cumulative key works for the webhook path because the provider sends
 * the running total and a redelivery carries the same one. An admin refund has no external total, so
 * computing it locally is a read-modify-write: the call times out, the operator retries, the local total
 * has not moved, a NEW key is derived — and the buyer is refunded twice while the merchant's transfer is
 * reversed twice.
 *
 * The provider's idempotency key therefore derives from THIS row's stable id, minted before anything
 * leaves the process, and never from recomputed mutable state. A retry of the same intent finds the same
 * row and sends the same key; the provider collapses it.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_refund_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('charge_reference');

            // What this attempt asked for — a delta, not a target. Two partial refunds of 500 are two
            // rows of 500, and neither has to know what the other did.
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);

            // What this attempt takes back from the merchant and returns of the platform's own commission.
            // Stored beside the buyer's refund rather than derived from it, because they are NOT
            // proportional: a fee with a fixed component makes the merchant's share of a half refund more
            // than half of their payout, and a figure recomputed later would use whatever the fee policy
            // says then rather than what it said when the refund was decided.
            $table->unsignedBigInteger('transfer_reversal_minor')->default(0);
            $table->unsignedBigInteger('fee_refund_minor')->default(0);

            // Derived from this row's id and stable for its lifetime. Held unique so a second row can
            // never reuse a key that already reached the provider.
            $table->string('idempotency_key')->unique();

            $table->string('status')->default('pending');
            $table->string('failure_reason')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // Every attempt against one charge, which is how a caller sees what has already been tried.
            $table->index(['provider', 'charge_reference'], 'billing_refund_attempts_charge_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_refund_attempts');
    }
};
