<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The package's own local subscription-state model. It always exists: the Stripe driver mirrors the
 * Stripe subscription into it; the Mollie/Adyen local engine treats it as the source of truth. The
 * SubscriptionPresenter reads a snapshot built from this row — no provider call on the hot path.
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->morphs('owner');
            $table->string('type')->default('default');
            $table->string('provider');
            $table->string('provider_id')->nullable();
            $table->string('status');
            $table->string('tier_key')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            // When the delinquency clock started (first entered a blocking state). The dunning +
            // suspension ladders read this timestamp, never a gateway status, so lockout is
            // outage-safe. Cleared once the subscription is no longer blocking.
            $table->timestamp('delinquent_since')->nullable();
            // The provider event timestamp (Unix seconds) last applied here — the plan-sync effect
            // uses it to ignore a retried or out-of-order older webhook.
            $table->unsignedBigInteger('synced_event_at')->nullable();
            // The current billing cycle, mirrored from the provider. Metered usage is accounted into
            // THIS window, not into a calendar month: an owner who renews on the 31st has neither.
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'status']);
            // One subscription-state row per owner per type. This index is also what makes the
            // concurrent first-delivery create-race converge: the losing insert hits the unique
            // violation, and the effect reruns against the now-existing row under the same recency
            // guard rather than answering the provider with a 500 (see SyncPlanFromSubscription).
            $table->unique(['owner_type', 'owner_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_subscriptions');
    }
};
