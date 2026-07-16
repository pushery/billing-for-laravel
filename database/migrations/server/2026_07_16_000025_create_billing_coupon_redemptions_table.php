<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The redemption ledger: WHO redeemed WHICH coupon, and against which subscription. It is the source of truth
 * behind a coupon's max-redemptions and per-owner limits — the unique index (coupon, owner) makes a second
 * redemption of the same coupon by the same owner impossible at the database level, so a double-apply race
 * cannot grant the discount twice.
 *
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->morphs('owner');
            $table->foreignId('coupon_id')->constrained('billing_coupons')->cascadeOnDelete();
            $table->unsignedBigInteger('subscription_id')->nullable()->index();
            $table->timestamp('redeemed_at');
            $table->timestamps();

            // One redemption of a given coupon per owner — the DB-level dedup behind a per-owner limit.
            $table->unique(['coupon_id', 'owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_coupon_redemptions');
    }
};
