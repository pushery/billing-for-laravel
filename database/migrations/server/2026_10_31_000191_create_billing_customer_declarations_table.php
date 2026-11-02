<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;

/**
 * What a customer declared about themselves before a purchase: whether they buy as a consumer or as a business.
 *
 * One row per declaration, never updated. A customer who declares again gets a second row, and the latest is the one
 * in force, so the history of what somebody said, and when, stays readable. `statement` is the wording the customer
 * confirmed, the application's own text, beside `statement_version` where the application names one. `audience` is the
 * `billing.audience` in force at that moment: under `business_only` the row is the confirmation that the customer buys
 * as a business, which is what lets the application treat a consumer who signed up anyway as one.
 *
 * A business declares its name and address, and its VAT number where it gives one. `vat_id_validation` is what the
 * bound `VatIdValidator` answered at that moment, `valid`, `invalid` or `unavailable`. `renewal_terms_consented` is the
 * separate consent to the renewal terms of an annual subscription, null where none was asked, with the wording in
 * `renewal_terms_statement`. `reference` is the key the purchase declarations of the same checkout were taken under.
 *
 * The owner columns are nullable and paired with `owner_erased_at`. A declaration is the evidence of which rules a sale
 * was made under, so the row outlives an erasure of its owner, unlinked, and retention removes it once the window of an
 * erased owner's records has passed, counted from the year it was declared in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_customer_declarations', function (Blueprint $table): void {
            $table->id();
            BillingSchema::nullableMorphs($table, 'owner', 'billing_customer_declarations_owner_index');
            $table->string('audience', 24);
            $table->string('customer_type', 16);
            $table->text('statement');
            $table->string('statement_version')->nullable();
            $table->string('business_name')->nullable();
            $table->text('business_address')->nullable();
            $table->string('vat_id', 32)->nullable();
            $table->string('vat_id_validation', 16)->nullable();
            $table->dateTime('vat_id_checked_at')->nullable();
            $table->boolean('renewal_terms_consented')->nullable();
            $table->text('renewal_terms_statement')->nullable();
            $table->string('reference')->nullable();
            $table->dateTime('declared_at');
            $table->dateTime('owner_erased_at')->nullable();
            $table->timestamps();

            $table->index('reference', 'billing_customer_declarations_reference_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_customer_declarations');
    }
};
