<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks an invoice that charges again what a credit note credited, and names the invoice it charges again.
 *
 * A chargeback on a locally raised invoice gets a credit note, and the credit note stays: it is a numbered document.
 * When the bank reverses the chargeback, the money is back, and an invoice of its own charges the credited amount
 * again. It is booked and counted like any invoice. What the link changes is what the invoice stands credited at:
 * the credit notes against it, less the invoices that charged them again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('reinstates_invoice_id')->nullable()->after('restates_invoice_id');
            $table->index('reinstates_invoice_id', 'billing_invoices_reinstates_index');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropIndex('billing_invoices_reinstates_index');
            $table->dropColumn('reinstates_invoice_id');
        });
    }
};
