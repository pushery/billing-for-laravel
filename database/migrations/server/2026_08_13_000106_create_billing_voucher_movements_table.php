<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a voucher movement is written down.
 *
 * The bookings for all three events were built, tested, and named in both chart-of-accounts configurations,
 * and none of them could arise from a real monthly run: `Issued` had no producer at all, and `Redeemed` and
 * `Expired` existed only as value objects the ledger returned and nothing stored. So an operator selling
 * vouchers exported none of it — the liability never appeared, and the turnover at redemption showed up with
 * no counter-entry.
 *
 * A movement is an EVENT, not a state, which is why it is its own table rather than more columns on the
 * voucher. A voucher can be redeemed many times, and the books need each one on the day it happened; the
 * voucher row can only ever say what is left today.
 *
 * `sale_gross_minor` is nullable because only a redemption has one — an issue and an expiry are not spent
 * against anything, and a zero there would read as a sale of nothing rather than as no sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_voucher_movements', function (Blueprint $table): void {
            $table->id();
            $table->string('event');
            $table->string('reference');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->unsignedBigInteger('sale_gross_minor')->nullable();
            $table->timestamp('occurred_on');
            $table->timestamps();

            // The export asks exactly one question of this table: what happened between two dates. Every
            // other read is incidental.
            $table->index('occurred_on');
            $table->index(['reference', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_voucher_movements');
    }
};
