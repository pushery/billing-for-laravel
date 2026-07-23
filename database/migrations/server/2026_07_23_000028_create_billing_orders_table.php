<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The package's own order: the local billing unit a driver without a provider-side order model (Mollie,
 * Adyen) assembles for a due cycle, processes, and produces an invoice from. Stripe drives its own cycle and
 * does not use these tables.
 *
 * The column names are provider-neutral — Adyen uses the same tables. Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_orders', function (Blueprint $table): void {
            $table->id();
            $table->morphs('owner');
            $table->string('provider');
            // The subscription this cycle belongs to, when it is a recurring order. Nullable because a
            // one-off order (a standalone add-on purchase) has no subscription.
            $table->foreignId('subscription_id')->nullable()->constrained('billing_subscriptions')->nullOnDelete();
            $table->bigInteger('total_minor');
            $table->char('currency', 3);
            $table->string('status')->default('open');
            // The cycle this order bills. period_start is half of the idempotency key below; period_end is
            // carried for the invoice and usage accounting.
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'status']);

            // Exactly one order per subscription cycle. This is the idempotency guarantee: a second attempt
            // to assemble the same cycle hits the unique violation instead of billing the customer twice.
            // A one-off order (subscription_id NULL) is exempt — NULLs are distinct, so many are allowed.
            $table->unique(['subscription_id', 'period_start'], 'billing_orders_subscription_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_orders');
    }
};
