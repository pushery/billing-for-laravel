<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The date the supply was actually made — EN 16931's BT-72.
 *
 * ## Why it is recorded rather than derived from the service period
 *
 * The obvious shortcut is to take the period's end: a supply covering July was delivered by the 31st. It is
 * wrong for the case that matters most here. A subscription billed IN ADVANCE issues its document on the
 * first of the month, and a derived BT-72 would state a delivery date in the future on a document dated
 * before it — asserting, in a machine-readable field an auditor reads, that something happened which has
 * not.
 *
 * The two terms also answer different questions. BG-14 says which period the charge covers; BT-72 says when
 * the supply occurred. For a service billed in arrears they often coincide, for one billed in advance they
 * cannot, and for a one-off sale there is a delivery date and no period at all. A field that is right in one
 * of those three cases is not a field, it is a coincidence.
 *
 * So it is stated by whoever knows, and absent otherwise: a document that does not say when it was delivered
 * emits no BT-72, which is what every document this package has issued so far already does.
 *
 * ## A date, like the period beside it
 *
 * BT-72 is a date in both syntaxes — `cbc:ActualDeliveryDate` in UBL, a format-102 `udt:DateTimeString` in
 * CII. Neither carries a time, so storing one would invite a timezone into a field that cannot express it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->date('delivered_on')->nullable()->after('service_period_end');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('delivered_on');
        });
    }
};
