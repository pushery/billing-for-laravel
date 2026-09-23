<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;

/**
 * Every dispute a provider opened, as the package heard about it.
 *
 * The card networks measure a seller by how many of its payments are disputed, and they count a dispute when it
 * is OPENED, whatever its outcome. The package recorded only the lost ones, on the correction they cause, so the
 * count a network acts on could not be read anywhere. This table is that count's source, and `DisputeRates` reads
 * it against the payments of the same window.
 *
 * Unique on the provider and the dispute's own reference, so a redelivered webhook records a case once. A case
 * counted twice is not a rounding difference: it moves a seller toward a threshold it has not reached.
 *
 * The merchant morph is nullable and paired with an erasure stamp, like the fees and charges beside it: a
 * dispute is a record of what happened to a sale, and it outlives the merchant it arose over, unlinked rather
 * than deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_disputes', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('dispute_reference');
            $table->string('payment_reference');
            BillingSchema::nullableMorphs($table, 'merchant');
            $table->string('account_reference')->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('reason');
            $table->string('reason_code')->nullable();
            $table->timestamp('evidence_due_by')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('merchant_erased_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'dispute_reference'], 'billing_disputes_unique');
            $table->index('opened_at', 'billing_disputes_opened_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_disputes');
    }
};
