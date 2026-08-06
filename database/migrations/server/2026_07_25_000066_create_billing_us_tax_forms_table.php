<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A seller's declaration about where they are taxed, for the United States regime.
 *
 * The table exists while the regime is switched off, and that is the point. A declaration is collected at
 * onboarding or not at all: a platform that waits until it needs one has to go back to sellers who have
 * moved, gone quiet, or stopped selling — after a year has closed, under a filing deadline. The chase ends
 * in withholding money from people who did nothing wrong.
 *
 * What is stored is the declaration and where the signed document lives, never the identifying number
 * itself. A taxpayer identification number is the most sensitive field in this whole area and the package
 * has no use for it: the number belongs wherever the signed form is kept, and a copy here would be a second
 * place to leak it from.
 *
 * The expiry is on the row because a foreign declaration goes stale on a schedule, and one that has expired
 * is worth exactly as much as one that was never given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_us_tax_forms', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('merchant', 'billing_us_tax_forms_merchant_index');
            $table->string('form_type', 16);
            // Asked-for and arrived look identical in every other field, and the difference decides whether
            // anything may be paid out under the regime.
            $table->string('status', 16);
            $table->date('signed_on');
            $table->date('expires_on')->nullable();
            // Where the signed document lives. Deliberately a reference, not the document and not the number.
            $table->string('document_reference')->nullable();
            $table->timestamp('merchant_erased_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_type', 'merchant_id', 'signed_on'], 'billing_us_tax_forms_seller_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_us_tax_forms');
    }
};
