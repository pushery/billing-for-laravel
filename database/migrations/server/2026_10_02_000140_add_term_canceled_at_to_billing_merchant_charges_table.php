<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the prepaid term a charge paid for was canceled, so that it is canceled once.
 *
 * A term cancellation moves money: it refunds the unused part through the package's refund verb, which
 * corrects both links of the chain once the provider confirms. A second call for the same charge, from a
 * double click or a retried job, would refund the unused part a second time, and the routed ledger caps a
 * refund only at what is left of the sale, not at what the term owes. The cancellation stamps this column
 * under a lock on the charge's row before the provider is asked, and a charge that carries it is refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->timestamp('term_canceled_at')->nullable()->after('settled_at');
        });
    }

    public function down(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->dropColumn('term_canceled_at');
        });
    }
};
