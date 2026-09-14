<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the subscription standing in the row began: the day a subscriber's withdrawal window opens.
 *
 * ## Why `created_at` does not answer it
 *
 * A subscription is unique on (owner, type, merchant_uid), so a customer who ends one and subscribes again gets
 * the same row back, and its `created_at` belongs to the first subscription. A window measured from there closes
 * before the second contract even began.
 *
 * ## Why it is nullable, and what a row without it answers
 *
 * Every row written before this existed has none, and a withdrawal measures those from `created_at`. That is
 * exact for a row that was never reused. For a row a returning customer reused before this column existed it is
 * too early, so a withdrawal in time can be refused as late, and only within one window's length after the
 * upgrade: a subscription that began before the upgrade is past its window by then either way. On Stripe the next
 * event about the subscription fills the column from the provider's own start date; on any row it can be set by
 * hand, and the refusal names the goodwill refund in the meantime.
 *
 * ## Why there is no index
 *
 * Nothing looks a subscription up BY this column. It is read off a row already found by its owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->timestamp('started_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('started_at');
        });
    }
};
