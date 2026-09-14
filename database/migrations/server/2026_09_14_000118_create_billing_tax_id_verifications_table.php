<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;

/**
 * What a tax authority's register said about a buyer's tax ID, and when the provider reported it.
 *
 * ## Why the answer has to be kept
 *
 * A checkout that collects a tax ID has only its FORMAT checked while the buyer is on the page, and Stripe Tax
 * reverses the charge on that format alone. Whether the number is registered is asked afterwards and answered
 * once. A sale reverse-charged to a number that turns out unregistered can owe the tax after all, and the only
 * record of when that became known is this answer.
 *
 * ## Why one row per answer rather than one per tax ID
 *
 * The answer moves, pending first and a verdict later, and each is a fact about a moment. Overwriting the pending
 * row with the verdict would erase when the platform could first have known. So a row is appended per status, and
 * the unique key is what turns a redelivered webhook into a no-op instead of a second row.
 *
 * ## Why the owner columns are nullable
 *
 * The table is RETAINED on erasure. The answer supports invoices that are kept for years, so the row stays and the
 * person is unlinked from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_tax_id_verifications', function (Blueprint $table): void {
            $table->id();
            BillingSchema::nullableMorphs($table, 'owner', 'billing_tax_id_verifications_owner_index');
            $table->string('provider');
            // The provider's customer the tax ID belongs to.
            $table->string('customer_reference');
            // The provider's own id for the tax ID. The same number entered twice is two objects there, each
            // checked on its own, so the number alone is not the key.
            $table->string('tax_id_reference');
            $table->string('type', 32);
            $table->string('value', 64);
            $table->string('status', 16);
            // What the register returned for the number, where it returned anything, in the provider's words.
            $table->string('verified_name')->nullable();
            $table->text('verified_address')->nullable();
            $table->timestamp('reported_at');
            $table->timestamp('owner_erased_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'tax_id_reference', 'status'], 'billing_tax_id_verifications_answer_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_tax_id_verifications');
    }
};
