<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\MigrationLocks;

/**
 * Which payment a delivery to a payment account is about.
 *
 * Such a delivery has no owner the package knows: the person who paid is the application's customer, so an erasure or
 * an export that looks for an owner never reaches it, and the payload names that person. The receiver now records the
 * provider's id of the payment beside the delivery, as the account's mapper names it, and the next migration indexes
 * it per account.
 *
 * Null on a delivery recorded before this column, on a platform delivery, and on one that names no payment. The
 * deliveries of an account that are still null are named from their stored payload the first time the account's
 * deliveries are asked for by payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_webhook_events', function (Blueprint $table): void {
            $table->string('payment_reference')->nullable();
        });
    }

    public function down(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_webhook_events', function (Blueprint $table): void {
            $table->dropColumn('payment_reference');
        });
    }
};
