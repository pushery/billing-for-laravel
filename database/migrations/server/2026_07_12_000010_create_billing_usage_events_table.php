<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The usage outbox: every unit of usage that will be billed, recorded locally BEFORE anyone tries to
 * hand it to a provider. A usage event is money, so it may be neither lost (a provider outage must not
 * silently drop revenue) nor billed twice (a retry must not double-charge). Both hang on this table:
 *
 *  - `identifier` is minted once, when the usage is recorded, and is what the provider dedups on. A
 *    retry replays the SAME identifier, so the provider recognizes it. It is never regenerated.
 *  - `source_key` is the CALLER's idempotency key. A send job that runs twice records the usage once.
 *  - `occurred_at` — never the flush time — is what crosses the wire, so a late flush still bills the
 *    usage into the cycle it actually happened in.
 *
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->morphs('owner');
            $table->string('meter_key');
            // The PROVIDER's meter name, stamped when the usage is recorded rather than looked up when
            // it is flushed: an owner who changes tier between the two must still have the usage they
            // already incurred billed the way it was incurred.
            $table->string('provider_meter')->nullable();
            $table->unsignedBigInteger('quantity');
            $table->timestamp('occurred_at');
            $table->string('period');

            $table->string('identifier')->unique();
            $table->string('source_key')->nullable()->unique();

            $table->string('state')->default('pending');
            $table->timestamp('reported_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();

            // Set on the source rows when the flusher coalesces a period's usage into one rollup event,
            // so a unit is reported once even though it was recorded a thousand times.
            $table->unsignedBigInteger('rolled_up_into')->nullable();
            // A rollup is retried with ITS OWN identifier and is never coalesced again. Folding an
            // already-attempted rollup into a fresh one would report the same units under a new
            // identifier — which is exactly how a provider bills them twice.
            $table->boolean('is_rollup')->default(false);

            $table->timestamps();

            // The flusher's claim query.
            $table->index(['state', 'next_attempt_at']);
            // Reconciliation and the gauge: an owner's usage on one meter in one cycle. Named
            // explicitly — the generated name would exceed MySQL's 64-character identifier limit.
            $table->index(['owner_type', 'owner_id', 'meter_key', 'period'], 'billing_usage_events_owner_meter_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_usage_events');
    }
};
