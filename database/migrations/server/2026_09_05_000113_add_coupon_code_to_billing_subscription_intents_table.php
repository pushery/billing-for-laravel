<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The coupon a customer typed, held across the redirect with the rest of what they asked for.
 *
 * ## Why the code has to survive the redirect at all
 *
 * Under a driver the package bills itself, a coupon is not applied at checkout -- there is no checkout to
 * apply it at. The customer is sent to the provider for a mandate, and the discount is a line the CYCLE
 * writes, months of them for a repeating coupon. So the moment the customer chooses the code and the
 * moment anything can act on it are separated by a browser round trip and a webhook, and the only thing
 * that crosses that gap is this row.
 *
 * ## Why it is not redeemed before the redirect instead
 *
 * Redeeming is spending: it burns a slot against the coupon's `max_redemptions` and, through the
 * (coupon, owner) unique index, uses up this owner's one allowed redemption forever. A customer who
 * closes the tab would have spent a coupon on a subscription that does not exist and could never spend it
 * again. Carrying the code and redeeming when the mandate lands keeps the existing promise of this table
 * -- an abandoned intent costs nobody anything.
 *
 * The code rather than the coupon id, deliberately. The id would need a foreign key onto a table an
 * install may not have populated at the time of the redirect, and the redemption at the other end has to
 * re-read the coupon anyway to check it is still redeemable then.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscription_intents', function (Blueprint $table): void {
            $table->string('coupon_code')->nullable()->after('tier_key');
        });
    }

    public function down(): void
    {
        Schema::table('billing_subscription_intents', function (Blueprint $table): void {
            $table->dropColumn('coupon_code');
        });
    }
};
