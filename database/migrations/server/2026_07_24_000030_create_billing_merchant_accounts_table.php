<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A merchant's account at the payment provider, and the capabilities the provider has confirmed for it.
 *
 * The capability flags are CACHED here rather than read live, because they arrive asynchronously — the
 * provider announces a finished verification by webhook, minutes or days after the merchant finished the
 * form. Reading them live on every routed charge would put a network call on the money path and still not
 * be current. Cached and refreshed by webhook, they are at worst stale, and stale is safe here: all three
 * default to false, so an account nobody has heard about yet cannot receive money.
 *
 * The column names are provider-neutral. `provider` plus `account_reference` is what identifies the account
 * to whichever driver routes the money; nothing in this table names one.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_merchant_accounts', function (Blueprint $table): void {
            $table->id();
            // The morph is NOT nullable, unlike the one on a retained invoice, because this row does not
            // outlive its merchant: a provider account reference is an operational key, not a financial
            // record, so the merchant axis of the erasure map purges it outright.
            $table->morphs('merchant');
            $table->string('provider');
            $table->string('account_reference');

            // What the provider has confirmed. All three default to false: an unknown capability must read
            // as "do not route", never as "probably fine".
            $table->boolean('charges_enabled')->default(false);
            $table->boolean('payouts_enabled')->default(false);
            $table->boolean('details_submitted')->default(false);

            // When the provider last told us. An operator seeing a merchant stuck at "cannot receive" needs
            // to know whether we simply never heard, or heard and were told no.
            $table->timestamp('capabilities_refreshed_at')->nullable();
            $table->timestamps();

            // One account per merchant per provider. A second account for the same merchant is not a
            // harmless duplicate: it splits their money across two identities the provider pays separately.
            $table->unique(['provider', 'merchant_type', 'merchant_id'], 'billing_merchant_accounts_merchant_unique');

            // A webhook arrives with the account reference and no local identity, so the lookup back to the
            // merchant runs on this column on every inbound Connect-style event.
            $table->unique(['provider', 'account_reference'], 'billing_merchant_accounts_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_merchant_accounts');
    }
};
