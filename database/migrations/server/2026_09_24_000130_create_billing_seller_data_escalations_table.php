<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;

/**
 * What the platform did about a seller whose record stayed incomplete, one row per episode.
 *
 * An episode opens the first time the record is found incomplete and closes when it is complete again. In
 * between it holds the stage reached, when each reminder went out and how it was delivered, and which
 * measure applied from when to when. The stage is stored rather than worked out from the dates: what has
 * to be answerable later is what the platform did, and a reconstruction can disagree with itself.
 *
 * Retained on the merchant axis, so the morph is nullable: the row is the evidence that a seller was asked
 * and what followed, and an erasure unlinks it rather than deleting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_seller_data_escalations', function (Blueprint $table): void {
            $table->id();
            BillingSchema::nullableMorphs($table, 'merchant');

            $table->string('stage');
            $table->timestamp('incomplete_since');
            // The field names that were missing when the record was last assessed, and whether any of
            // them is required of this seller. The values themselves are never copied here.
            $table->json('missing_fields');
            $table->boolean('missing_required')->default(false);

            $table->timestamp('first_reminded_at')->nullable();
            $table->timestamp('second_reminded_at')->nullable();
            // One entry per channel a reminder was actually handed to: which reminder, the channel, the
            // recipient and when.
            $table->json('deliveries')->nullable();

            // The measure in force now, and since when. A measure that ends moves into `ended_measures`
            // with its end and the reason, so a withholding that was converted into a suspension stays on
            // the record beside it.
            $table->string('measure')->nullable();
            $table->timestamp('measure_started_at')->nullable();
            $table->json('ended_measures')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('merchant_erased_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_type', 'merchant_id', 'resolved_at'], 'billing_seller_data_escalations_open_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_seller_data_escalations');
    }
};
