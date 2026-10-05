<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the tiers a subscription held earlier in its running period add to the plan charge that closes it.
 *
 * The local engine bills a period when it closes, at the tier in force then. A tier changed in the middle of the
 * period held the days before the change, and those days are owed at that tier's price. The difference between
 * that price and the new one, times the seat-days the earlier tier held, is kept here in minor units. The cycle
 * divides it by the period's days, adds it to the plan charge and starts the next period at zero.
 *
 * ## Why it is signed
 *
 * An upgrade leaves a negative value, because the days before it were on the cheaper tier, and a downgrade a
 * positive one. Every change adds its own term to the sum, so any number of changes in one period come out as the
 * value of the days each tier held.
 *
 * ## Why one column and no history table
 *
 * The same reason as the seat columns: the cycle needs the total, not the steps, and nothing looks a subscription
 * up by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->bigInteger('tier_adjustment_accrued')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('tier_adjustment_accrued');
        });
    }
};
