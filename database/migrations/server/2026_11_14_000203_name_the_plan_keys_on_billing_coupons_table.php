<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\MigrationLocks;

/**
 * The plans a coupon discounts on a driver the package bills itself: a JSON list of plan keys, whose lines alone the
 * discount line takes its share from. A coupon that names none keeps it null and discounts every line of the cycle, so
 * every coupon issued before stays what it was. Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_coupons', function (Blueprint $table): void {
            $table->json('plan_keys')->nullable()->after('trial_days');
        });
    }

    public function down(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_coupons', function (Blueprint $table): void {
            $table->dropColumn('plan_keys');
        });
    }
};
