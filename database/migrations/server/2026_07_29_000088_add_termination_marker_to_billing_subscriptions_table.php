<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The marker that makes an expired subscription TERMINAL rather than merely inactive.
 *
 * ## Why a column and not a status
 *
 * `status` already carries `ended`, and a reader could take that for the answer. It is not: every webhook
 * overwrites `status`, so a provider that reports the subscription active again — a retried invoice, a late
 * delivery, somebody clicking reactivate upstream — would move the row straight back to `active` and the
 * customer would have their access returned by a payment that was, by the decision, a NEW purchase.
 *
 * The distinction is the same one `RecoveredReceivable` draws on the receipt side, and it has to be recorded
 * for the same reason: an outcome that can be re-derived from the current values is not an outcome, it is a
 * coincidence of them.
 *
 * ## Terminal for THIS subscription, not for this row forever
 *
 * The uniqueness key is (owner, type, merchant_uid), so a customer has exactly one row per merchant and a new
 * signup necessarily re-uses it. "Terminal" therefore means: this PROVIDER SUBSCRIPTION does not come back. A
 * later event carrying the same provider reference is refused; one carrying a different reference is a new
 * signup, which clears the marker and takes the row over. That is precisely the decision — a payment after
 * expiry is a new contract, not a revival — expressed in the only identity the provider gives us.
 *
 * Nullable and additive: every existing row has never been terminated, which is what null means here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->timestamp('terminated_at')->nullable()->after('payment_reminded_on');
        });
    }

    public function down(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('terminated_at');
        });
    }
};
