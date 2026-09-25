<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a settlement issued in place of one that was canceled, and names it.
 *
 * Not a reissue. A reissue states a sale again beside a document that still stands, so every total skips
 * it. Here the earlier settlement was canceled, the cancellation takes its amounts back, and this one
 * states the supply as it should have been stated. It is booked and counted like any settlement.
 *
 * What the link changes is the claim on the charge. The first settlement of a sale holds it for good, and
 * the one issued in its place stands in for it rather than claiming the charge a second time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('restates_invoice_id')->nullable()->after('reissue_of_invoice_id');
            $table->index('restates_invoice_id', 'billing_invoices_restates_index');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropIndex('billing_invoices_restates_index');
            $table->dropColumn('restates_invoice_id');
        });
    }
};
