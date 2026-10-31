<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\MigrationLocks;

/**
 * The interval a subscription is billed at, where its tier is sold at more than one.
 *
 * A tier may be sold monthly and yearly under its one key, and the tier key alone then no longer says which price
 * the subscription pays. The provider's webhook writes the interval its price sells the tier at, and what describes
 * the subscription reads the plan at that interval: the contract confirmation, the plan the overview names, the MRR.
 *
 * Null on a row written before this column, and on one whose price the catalog names at no interval. Such a row reads
 * the tier's default interval, as every row did before.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->string('billing_interval', 8)->nullable();
        });
    }

    public function down(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('billing_interval');
        });
    }
};
