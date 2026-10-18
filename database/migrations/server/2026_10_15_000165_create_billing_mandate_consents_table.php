<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;

/**
 * The proof of each SEPA direct debit mandate an account holder gave on the application's own form.
 *
 * One row per mandate, unique on the provider and the provider's mandate id, written once. It keeps the text the
 * account holder confirmed rather than a pointer to it: a wording changes, and a mandate stays what it was when it
 * was given. Of the account it keeps the country and the last four characters, which is what identifies the
 * mandate to a person; the full IBAN stays with the provider.
 *
 * The owner columns are nullable and paired with `owner_erased_at`. The row outlives an erasure of its owner,
 * unlinked, because the payee has to be able to show a mandate for as long as a debit drawn under it can be
 * disputed. Retention removes it once the window of an erased owner's financial records has passed.
 *
 * The moments are DATETIME, as every moment the package keeps in UTC is: a TIMESTAMP passes through the session
 * time zone on MySQL. Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_mandate_consents', function (Blueprint $table): void {
            $table->id();
            BillingSchema::nullableMorphs($table, 'owner', 'billing_mandate_consents_owner_index');
            $table->string('provider');
            $table->string('mandate_reference');
            $table->string('reference', 35);
            $table->string('account_holder');
            $table->string('account_country', 2);
            $table->string('account_last_four', 4);
            $table->string('notify_email')->nullable();
            $table->string('creditor_identifier');
            $table->string('language', 8);
            $table->text('statement');
            $table->unsignedSmallInteger('notice_days');
            $table->date('signed_on');
            $table->dateTime('consented_at');
            $table->string('consent_ip', 45)->nullable();
            $table->text('consent_user_agent')->nullable();
            $table->dateTime('owner_erased_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'mandate_reference'], 'billing_mandate_consents_mandate_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_mandate_consents');
    }
};
