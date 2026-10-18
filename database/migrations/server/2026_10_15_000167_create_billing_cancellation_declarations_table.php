<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;

/**
 * Every cancellation declared through the cancellation button, kept as it was declared.
 *
 * The confirmation page asks for the kind of cancellation and, for an extraordinary one, its reason; who is
 * canceling and their address; which contract; when it should end; and where the confirmation goes. The row stores
 * what the person entered, as they entered it: `contract` is the text they chose or typed, and `subscription_id`
 * is filled only where a signed-in person picked one of their own contracts. `declared_at` is the receipt, the
 * moment the declaration arrived, so it lives on the row rather than only in a mail.
 *
 * The owner columns are nullable and paired with `owner_erased_at`. A received cancellation is a business letter
 * the business keeps, so the row outlives an erasure of its owner, unlinked, and a declaration made without a
 * signed-in owner is written unlinked from the start. Retention removes both once the window of an erased owner's
 * records has passed, counted from the year the declaration arrived in.
 *
 * The moments are DATETIME, as every moment the package keeps in UTC is. Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_cancellation_declarations', function (Blueprint $table): void {
            $table->id();
            $table->string('token', 64)->unique();
            BillingSchema::nullableMorphs($table, 'owner', 'billing_cancellation_declarations_owner_index');
            $table->foreignId('subscription_id')->nullable()->constrained('billing_subscriptions')->nullOnDelete();
            $table->string('kind', 32);
            $table->text('reason')->nullable();
            $table->string('name');
            $table->string('email');
            $table->text('contract');
            $table->string('ends', 32);
            $table->date('ends_on')->nullable();
            $table->string('confirmation_email');
            $table->string('locale', 8);
            $table->dateTime('declared_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('status', 32);
            $table->string('failure')->nullable();
            $table->string('charge_reference')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('owner_erased_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'declared_at'], 'billing_cancellation_declarations_status_index');
            // PostgreSQL creates no index for a foreign key, and a subscription's declarations are read by it.
            $table->index('subscription_id', 'billing_cancellation_declarations_subscription_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_cancellation_declarations');
    }
};
