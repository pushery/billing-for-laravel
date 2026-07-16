<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A credit note references the invoice it credits. `credited_invoice_id` already links to the local row,
 * but that row may not exist (a credit note for an invoice issued before this package started persisting
 * them). The provider's own number of the credited invoice is what an EN 16931 credit note must carry as
 * its preceding-invoice reference (BG-3), so it is frozen onto the credit-note row — the renderer reads it
 * without a lookup, and the reference survives even when the original was never stored locally.
 *
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('credited_invoice_number')->nullable()->after('credited_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('credited_invoice_number');
        });
    }
};
