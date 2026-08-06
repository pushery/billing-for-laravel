<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a merchant owes the platform, per currency — the sub-ledger a clawback lands in when the money is
 * already gone.
 *
 * A reversal can fail: the merchant's provider balance may hold nothing to take back. Without somewhere for
 * the shortfall to sit, that is a loss nobody sees — the reversal simply did not happen, and no row says so.
 * So the balance is signed and allowed to go negative, and the next settlement is applied against it before
 * anything is paid out.
 *
 * Deliberately NOT the buyer credit balance. That one is a claim on future invoices for somebody buying from
 * the platform; this is a debt owed by somebody selling through it. Sharing a table would put a payable and
 * a receivable in the same column and make one person's prepayment offsettable against another's clawback.
 *
 * The unique key spans the merchant AND the currency, and the merchant half is nullable so an erasure can
 * unlink it. That combination works BECAUSE a unique index treats nulls as distinct: several erased
 * merchants' balances can sit side by side without colliding. This is the exact inverse of the decision on
 * the webhook account reference, where nulls being distinct would have broken deduplication and the column
 * therefore defaults to an empty string — same property of the same index type, opposite consequence, so it
 * is spelled out on both sides rather than left to be re-derived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_merchant_balances', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('merchant');
            $table->char('currency', 3);
            $table->bigInteger('balance_minor')->default(0);
            $table->timestamp('merchant_erased_at')->nullable();
            $table->timestamps();

            $table->unique(['merchant_type', 'merchant_id', 'currency'], 'billing_merchant_balances_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_merchant_balances');
    }
};
