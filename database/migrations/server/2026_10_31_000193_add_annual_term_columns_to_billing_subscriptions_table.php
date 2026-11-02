<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\MigrationLocks;

/**
 * How a consumer's annual subscription goes on once its year ends.
 *
 * `monthly_terms_at` is when the subscription began under terms that name the monthly price it goes on at after a year:
 * written with the first event of every subscription from now on, and kept for that subscription. A row written before
 * the column keeps null, and that is the point of it. Such a subscription was sold without a monthly price, so after its
 * year it goes on at a twelfth of the price of the year, because a higher price agreed by staying silent would not bind
 * the customer.
 *
 * `provider_schedule_id` is the schedule at the provider that holds the subscription, where one does. Under Stripe a
 * consumer's annual subscription is held by a subscription schedule, the year that was paid for and then the monthly
 * price, and the column mirrors the subscription's `schedule` from every event about it. A change the package makes to
 * the subscription takes the schedule off first, which Stripe asks for, and reads here whether there is one.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dateTime('monthly_terms_at')->nullable();
            $table->string('provider_schedule_id')->nullable();
        });
    }

    public function down(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['monthly_terms_at', 'provider_schedule_id']);
        });
    }
};
