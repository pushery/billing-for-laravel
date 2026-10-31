<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\MigrationLocks;

/**
 * The interval a scheduled plan change moves the subscription to, beside the tier it moves to.
 *
 * A change to a shorter interval waits for the end of the period already paid for, as a downgrade does: a yearly
 * subscriber who chooses the monthly price keeps the year and pays monthly after it. The tier alone cannot carry that
 * change, since the tier is the same at either price, so the schedule names the interval as well. Null where the
 * change keeps the interval the subscription is billed at.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->string('scheduled_billing_interval', 8)->nullable();
        });
    }

    public function down(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('scheduled_billing_interval');
        });
    }
};
