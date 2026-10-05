<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which event a correcting document corrects, where that event has no row of its own.
 *
 * ## The hole this closes
 *
 * A refund that went through the provider is recorded as a reversal, and its correcting documents name it in
 * `refund_attempt_id`. The amount they correct is what the routed ledger reported after capping it, so a
 * redelivered confirmation moves nothing and issues nothing. A prepaid term cancellation has no reversal row
 * and moves nothing through the ledger: it hands the corrector an amount it computed. Called twice without a
 * key, it would issue a second correcting document on each side, each with its own number from the correction
 * series.
 *
 * The key names the event instead, and the index refuses a second document for the same event against the same
 * original. The original is part of the key because one event corrects two documents, the creator's settlement
 * and the buyer's receipt, and each of them gets exactly one correction for it.
 *
 * ## Why the key leads the index
 *
 * The question asked of it is "does this event already have its documents?", which names the key and not the
 * original. Leading with the key answers that from the index; the original behind it keeps the two sides apart.
 *
 * ## What this can refuse on an existing installation
 *
 * Nothing that already exists. The column is null on every row written before this ran, and every engine here
 * treats null as distinct in a unique index, so no historical pair can collide. Only documents written with a
 * key from here on are constrained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('correction_key')->nullable()->after('refund_attempt_id');

            $table->unique(
                ['correction_key', 'credited_invoice_id'],
                'billing_invoices_correction_key_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropUnique('billing_invoices_correction_key_unique');
            $table->dropColumn('correction_key');
        });
    }
};
