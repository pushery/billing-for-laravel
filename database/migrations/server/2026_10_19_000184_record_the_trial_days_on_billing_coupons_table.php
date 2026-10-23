<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\MigrationLocks;

/**
 * The days of trial a coupon gives the subscription it is redeemed on, by plan: a JSON object from a plan key to a
 * number of days, with `*` for every plan the object does not name. A coupon that gives no trial keeps it null, so
 * every coupon issued before stays what it was. Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_coupons', function (Blueprint $table): void {
            $table->json('trial_days')->nullable()->after('redeemed_count');
        });
    }

    public function down(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_coupons', function (Blueprint $table): void {
            $table->dropColumn('trial_days');
        });
    }
};
