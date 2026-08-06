<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sale whose payout waits for the buyer, and the clock it waits on.
 *
 * The money is not here. It stays with the payment provider until a release is triggered, and this table
 * records only which sales are waiting and how long they may wait — the platform never holds the funds,
 * because holding other people's money is a regulated activity and doing it accidentally is doing it without
 * a license.
 *
 * Two dates rather than one: the day the buyer's silence becomes consent, and the day a decision has to
 * happen regardless. The second exists because the provider will not delay a payout indefinitely, and a
 * clock that can outrun that limit is a product defect rather than a long wait.
 *
 * The merchant axis is nullable so an erasure can unlink a settled hold instead of deleting the record of a
 * payment that happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_buyer_protection_holds', function (Blueprint $table): void {
            $table->id();
            $table->string('charge_reference')->unique();
            $table->nullableMorphs('merchant', 'billing_protection_holds_merchant_index');
            $table->string('currency', 3);
            $table->integer('charge_minor');
            $table->integer('platform_fee_minor')->default(0);
            $table->integer('seller_net_minor')->default(0);
            $table->integer('buyer_refund_minor')->default(0);
            // Long enough for the longest state value; a column that truncates one turns a settled hold into
            // an unknown state, and the query that looks for open ones then misses it forever.
            $table->string('state', 32);
            $table->timestamp('confirm_by');
            $table->timestamp('decide_by');
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('merchant_erased_at')->nullable();
            $table->timestamps();

            // The scheduler asks one question — which holds are still open and past a date — so the index is
            // on the pair it filters by, not on either column alone.
            $table->index(['state', 'confirm_by'], 'billing_protection_holds_state_confirm_index');
            $table->index(['state', 'decide_by'], 'billing_protection_holds_state_decide_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_buyer_protection_holds');
    }
};
