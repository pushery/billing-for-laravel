<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The package's OWN coupon/discount model — a coupon is not a provider-only concept (other billing packages define
 * them locally too). The local engine applies these directly; the Stripe driver optionally maps a coupon to a
 * Stripe coupon via `provider_coupon_id`. `value` holds a percentage (for a percent coupon) or an amount in
 * minor units (for a fixed coupon), and `currency` scopes a fixed amount. `redeemed_count` is denormalized for
 * a cheap max-redemptions check; the redemption ledger (billing_coupon_redemptions) is the source of truth.
 *
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('type');                                  // percent | fixed
            $table->unsignedBigInteger('value');                     // percentage, or amount in minor units
            $table->char('currency', 3)->nullable();                 // scopes a fixed-amount coupon
            $table->string('duration');                              // once | repeating | forever
            $table->unsignedInteger('duration_in_cycles')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redeemed_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->string('provider_coupon_id')->nullable();        // optional Stripe-coupon mapping
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_coupons');
    }
};
