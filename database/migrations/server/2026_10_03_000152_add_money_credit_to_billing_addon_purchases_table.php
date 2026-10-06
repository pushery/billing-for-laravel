<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a purchase put money on the buyer's credit balance, recorded when it was made.
 *
 * ## Why the purchase has to hold it
 *
 * Two things read that answer long after the sale: the prepaid volume a supervisory threshold is measured
 * against, and the refund that takes the credit back off the balance. Worked out again from the add-on catalog
 * as it stands at the time of reading, a credit add-on renamed, taken out of sale or changed to grant units
 * would turn every earlier purchase of it into something it never was: its volume would drop out of the
 * supervisory figure, and its refund would leave the credit on the balance. The purchase knows what it did, so
 * it keeps the answer.
 *
 * ## Why it is nullable, and stays nullable
 *
 * Every purchase recorded before this column existed has none. Null means "not recorded", and each reader
 * treats it the way it treated every purchase before: from the catalog as it stands. It is never a default
 * standing in for an answer.
 *
 * ## Why there is no index
 *
 * Nothing looks a purchase up by this column alone. The volume reads it alongside the currency and the date
 * of a window, and the refund off a row already found by its payment reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_addon_purchases', function (Blueprint $table): void {
            $table->boolean('money_credit')->nullable()->after('declaration_reference');
        });
    }

    public function down(): void
    {
        Schema::table('billing_addon_purchases', function (Blueprint $table): void {
            $table->dropColumn('money_credit');
        });
    }
};
