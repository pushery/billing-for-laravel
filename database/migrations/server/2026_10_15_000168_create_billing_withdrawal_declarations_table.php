<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;

/**
 * Every withdrawal declared through the withdrawal function, kept as it was declared.
 *
 * The function takes the person's name, what identifies the contract and the electronic address the confirmation
 * goes to. The row stores them as the person entered them: `contract` is the text they chose or typed, and
 * `subscription_id` is filled only where a signed-in person picked one of their own contracts. `declared_at` is the
 * receipt. A withdrawal is in time when it was sent before the withdrawal period ran out, so the moment it was sent
 * lives on the row rather than only in a mail.
 *
 * The owner columns are nullable and paired with `owner_erased_at`. A received withdrawal is a business letter the
 * business keeps, so the row outlives an erasure of its owner, unlinked, and a declaration made without a signed-in
 * owner is written unlinked from the start. Retention removes both once the window of an erased owner's records has
 * passed, counted from the year the declaration arrived in.
 *
 * The moments are DATETIME, as every moment the package keeps in UTC is. Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_withdrawal_declarations', function (Blueprint $table): void {
            $table->id();
            $table->string('token', 64)->unique();
            BillingSchema::nullableMorphs($table, 'owner', 'billing_withdrawal_declarations_owner_index');
            $table->foreignId('subscription_id')->nullable()->constrained('billing_subscriptions')->nullOnDelete();
            $table->string('name');
            $table->text('contract');
            $table->string('confirmation_email');
            $table->string('locale', 8);
            $table->dateTime('declared_at');
            $table->string('status', 32);
            $table->string('failure')->nullable();
            $table->string('charge_reference')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('owner_erased_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'declared_at'], 'billing_withdrawal_declarations_status_index');
            // PostgreSQL creates no index for a foreign key, and a subscription's declarations are read by it.
            $table->index('subscription_id', 'billing_withdrawal_declarations_subscription_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_withdrawal_declarations');
    }
};
