<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the payment provider charged the platform, as its own record.
 *
 * A provider fee is a service the provider supplied — where they are established abroad, one the recipient
 * owes the tax on — and a booking of it needs something to book. Until now the only place a dispute fee
 * appeared was in flight, on the event that reported it; nothing held it afterwards, so nothing could post
 * it and nobody could reconcile it. It is deliberately NOT netted off the amount it accompanies: folded in,
 * it would shrink the turnover being corrected and leave every figure adding up.
 *
 * Unique on the provider and the reference it was charged against, so a redelivered webhook books the fee
 * once. A fee charged twice for one dispute is not a rounding difference — it is an expense that never
 * happened, on an account that self-assesses tax, so it also invents the tax.
 *
 * The merchant morph is nullable and paired with an erasure stamp, like the charges and balances beside it:
 * the fee is the platform's own cost and outlives the merchant it arose over, unlinked rather than deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_provider_fees', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('reference');
            $table->nullableMorphs('merchant');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('cause')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('merchant_erased_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'reference'], 'billing_provider_fees_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_provider_fees');
    }
};
