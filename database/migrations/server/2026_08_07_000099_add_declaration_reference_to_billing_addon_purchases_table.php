<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The key the buyer's pre-purchase declarations were recorded against, carried onto the purchase.
 *
 * ## Why the purchase has to hold it
 *
 * The declarations are recorded BEFORE the buyer leaves for the provider, so at that moment the purchase
 * has no reference of any kind — the package mints its own key and sends it along as opaque metadata. What
 * comes back on the webhook is a session id, and the row is written under that.
 *
 * Those are two different strings. Without this column the receipt, which knows the PAYMENT id, has no path
 * to a declaration recorded under a minted key: `WithdrawalConsentLedger::forPayment()` walks
 * payment reference -> purchase -> `reference`, and `reference` is the session. The lookup answers null,
 * and null on that path reads as "the buyer declared nothing" — the one answer that is both wrong and
 * indistinguishable from the truth.
 *
 * ## Why it is nullable, and stays nullable
 *
 * Every purchase made before this existed has none, and so does every purchase on an install with no
 * consumer-rights profile — which is the overwhelming majority and is not a deficiency. Null means "no
 * declaration traveled with this purchase", which is an ordinary state; it is emphatically not a default
 * standing in for one.
 *
 * ## Why there is no index
 *
 * Nothing looks a purchase up BY this column. It is read off a row already found by its payment reference,
 * which is the indexed path. An index here would cost writes on every purchase to serve no query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_addon_purchases', function (Blueprint $table): void {
            $table->string('declaration_reference')->nullable()->after('payment_reference');
        });
    }

    public function down(): void
    {
        Schema::table('billing_addon_purchases', function (Blueprint $table): void {
            $table->dropColumn('declaration_reference');
        });
    }
};
