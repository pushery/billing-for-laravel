<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which buyer a customer reference means INSIDE a given merchant account.
 *
 * A provider's customer ids are unique within the account that issued them, not across a platform and all
 * its merchants. The package's existing lookup is global — one column on the billable — which is correct
 * for a single seller and silently wrong the moment a second account exists: two different people, holding
 * the same id under two merchants, resolve to whichever one the global lookup happens to find first. That
 * is not a failed lookup anybody would notice; it is a webhook attributed to a stranger, and with it their
 * invoice, their receipt and their data.
 *
 * The account reference is part of the key, so the same id under two accounts is two rows.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_merchant_customers', function (Blueprint $table): void {
            $table->id();
            $table->morphs('owner');
            $table->string('provider');
            $table->string('account_reference');
            $table->string('customer_reference');
            $table->timestamps();

            // The identity of a customer WITHIN an account. Without the account column this would be the
            // global key that cannot tell two merchants' customers apart.
            $table->unique(
                ['provider', 'account_reference', 'customer_reference'],
                'billing_merchant_customers_scoped_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_merchant_customers');
    }
};
