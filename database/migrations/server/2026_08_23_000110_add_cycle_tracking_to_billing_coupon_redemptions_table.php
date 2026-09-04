<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many cycles a redeemed coupon has already discounted, and which one it last discounted.
 *
 * The coupon itself already says how long it runs — `duration` is `once`, `repeating` or `forever`, and a
 * repeating one carries `duration_in_cycles`. What was missing is the counting: without it, `repeating`
 * and `forever` are the same thing, because nothing anywhere knows a cycle has gone by. A coupon sold as
 * "three months half price" discounted every invoice for the life of the subscription, and the only
 * evidence was in a customer's favor, which is the kind nobody reports.
 *
 * `applied_count` is the count. `last_applied_period` is what makes incrementing it safe.
 *
 * The period key matters more than it looks. A cycle is priced BEFORE its order is claimed — it has to
 * be, since the total decides what to claim — and the claim can then lose to a concurrent run or an
 * order that already exists. Counting at pricing time would therefore count attempts rather than cycles,
 * and a retried run would burn a customer's remaining months without billing anything. Recording WHICH
 * period was discounted makes a second look at the same period a no-op by construction, which is a
 * cheaper guarantee than a transaction spanning two different concerns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_coupon_redemptions', function (Blueprint $table): void {
            $table->unsignedInteger('applied_count')->default(0)->after('subscription_id');
            $table->string('last_applied_period')->nullable()->after('applied_count');
        });
    }

    public function down(): void
    {
        Schema::table('billing_coupon_redemptions', function (Blueprint $table): void {
            $table->dropColumn(['applied_count', 'last_applied_period']);
        });
    }
};
