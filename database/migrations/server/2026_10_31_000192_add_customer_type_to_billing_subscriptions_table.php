<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\MigrationLocks;

/**
 * Whether the customer bought the subscription as a consumer or as a business, frozen when it began.
 *
 * An annual subscription continues by that answer: a consumer's goes on monthly after its first year, a business's
 * renews for another year. It is read from the row and not asked again, so a declaration the customer makes later does
 * not reinterpret a year that is already running. A new subscription that takes the row over freezes its own.
 *
 * Null on a row written before this column, until the next event for its subscription writes the type as it is
 * resolved then: what the customer declared, or what the application knows of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->string('customer_type', 16)->nullable();
        });
    }

    public function down(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('customer_type');
        });
    }
};
