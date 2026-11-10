<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\MigrationLocks;

/**
 * The other id a delivery's payment goes by.
 *
 * A payment can go by two ids: on Stripe the Checkout Session until the customer tries to pay, and its payment intent
 * from then on. The delivery of an expired session that holds an intent is about the payment an application by then
 * knows under the intent, and its payload names the person who paid. The receiver now records the intent here, beside
 * the session in `payment_reference`, and the next migration indexes it per account, so an erasure or an export asked
 * by either id finds the delivery.
 *
 * Where a payment goes by one id only, this holds that id too, so a delivery once named is never read again to name
 * it. Null on a delivery recorded before this column, on a platform delivery, and on one that names no payment. The
 * deliveries of an account that are still null are named from their stored payload the first time the account's
 * deliveries are asked for by payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_webhook_events', function (Blueprint $table): void {
            $table->string('payment_alias')->nullable();
        });
    }

    public function down(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_webhook_events', function (Blueprint $table): void {
            $table->dropColumn('payment_alias');
        });
    }
};
