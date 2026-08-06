<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a document that states an existing sale again, rather than stating a new one.
 *
 * A buyer who took a short receipt may ask for a full invoice afterwards. They get a real document with its
 * own number, and the receipt they already have stays exactly as it was — reaching back to change an issued
 * document is the thing a numbered series exists to prevent.
 *
 * Which leaves two documents over one sale, and that is the whole reason for this column. Anything that sums
 * documents — a tax return, a turnover threshold, a booking batch — would otherwise count the sale twice and
 * declare tax that was never taken. The column is what those readers skip on, so the second document is a
 * restatement rather than a second sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('reissue_of_invoice_id')->nullable()->after('credited_invoice_number');
            $table->index('reissue_of_invoice_id', 'billing_invoices_reissue_index');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropIndex('billing_invoices_reissue_index');
            $table->dropColumn('reissue_of_invoice_id');
        });
    }
};
