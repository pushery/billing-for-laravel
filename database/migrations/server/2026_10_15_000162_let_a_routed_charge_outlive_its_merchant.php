<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\BillingSchema;

/**
 * A routed charge outlives the merchant it names, as the table that created it already says it must.
 *
 * An erasure keeps a merchant's financial records and unlinks them: it sets `merchant_type` and `merchant_id` to
 * null and stamps `merchant_erased_at`. Every other table the merchant axis retains declares its merchant columns
 * nullable. This one declared them with `morphs()`, NOT NULL, so erasing a merchant with a single routed charge failed
 * on the constraint and the whole erasure rolled back. The columns become nullable here, through the helpers, so they
 * keep the type the host key setting gave them.
 *
 * The rollback leaves the columns nullable, as the migrations that let invoices and credit movements outlive an
 * erasure do: once a merchant has been erased, a kept charge carries none, and NOT NULL would fail on exactly those
 * rows. A repeated `up()` converges.
 *
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            BillingSchema::hostType($table, 'merchant_type')->nullable()->change();
            BillingSchema::hostKey($table, 'merchant_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately empty, see above: the columns stay nullable.
    }
};
