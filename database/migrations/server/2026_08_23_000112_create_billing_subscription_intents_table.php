<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What somebody asked for, held across a redirect they may never come back from.
 *
 * ## Why a row exists at all
 *
 * A provider with no synchronous setup call establishes a mandate through a first payment the customer
 * completes on the provider's own page. The mandate then arrives over the webhook — carrying the customer,
 * the mandate id and the payment, and nothing about what the customer was trying to BUY. Without a record
 * of the intention there is no way to turn that mandate into the subscription it was for: the tier is
 * chosen before the redirect and known nowhere afterwards.
 *
 * ## Why it is keyed on the PAYMENT and not on the customer
 *
 * The two look interchangeable and are not. Establishing a mandate is also what happens when a customer
 * merely adds a second payment method — same event, same customer, no subscription intended. Keyed on the
 * customer, that would consume a pending intent and hand somebody a subscription they never asked for, on a
 * day they were doing something else entirely. The payment reference is what makes the answer belong to
 * the question.
 *
 * ## Why nothing writes a subscription until the mandate lands
 *
 * The alternative — write the subscription first, in a pending state, and roll it back on abandonment —
 * needs a sweep for abandoned activations and produces a row that is briefly a lie. Here a customer who
 * closes the tab leaves an unclaimed intent and nothing else: no half subscription, no cleanup job, and
 * nobody looking at the subscriptions table sees anything that did not happen.
 *
 * `claimed_at` rather than a delete, because the delivery repeats: a redelivered mandate has to find the
 * intent it already used and recognize it as spent. A deleted row is indistinguishable from one that never
 * existed, and the second delivery would then look like a mandate for a customer who never subscribed.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_subscription_intents', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->string('provider', 40);
            $table->string('tier_key');
            // Unique: the provider issues one payment per attempt, so two intents on one payment is not a
            // race to resolve but a bug to refuse. The index is also what makes a redelivery cheap to
            // answer -- the lookup is the constraint.
            $table->string('payment_reference')->unique();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            // The sweep an operator runs asks "what is still open for this owner", so the index leads with
            // the owner and carries the claim state.
            //
            // NAMED, and the name is not decoration. Left to the framework this index is called
            // `billing_subscription_intents_owner_type_owner_id_claimed_at_index` -- 65 characters, one
            // over MySQL's limit for an identifier. PostgreSQL accepts it and silently truncates to 63;
            // MySQL refuses the whole ALTER with error 1059. So the generated name is a migration that
            // works on one of the two engines this package is proven on and cannot run on the other,
            // and the engine it fails on is the one a consumer is more likely to be using.
            $table->index(['owner_type', 'owner_id', 'claimed_at'], 'billing_intents_owner_open_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_subscription_intents');
    }
};
