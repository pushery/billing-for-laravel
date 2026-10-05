<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two references the package reads by value get an index.
 *
 * `billing:usage:flush` settles the sources of every reported rollup with `where('rolled_up_into', …)`, once per
 * rollup and run, on the table that grows fastest; a credit note sums the corrections of its invoice with
 * `where('credited_invoice_id', …)`. Neither column was in an index, so each read scanned its whole table, and on
 * MySQL an update that scans locks every row it reads until the transaction ends, recorded usage included.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_usage_events', function (Blueprint $table): void {
            $table->index('rolled_up_into', 'billing_usage_events_rolled_up_into_index');
        });

        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->index('credited_invoice_id', 'billing_invoices_credited_invoice_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropIndex('billing_invoices_credited_invoice_id_index');
        });

        Schema::table('billing_usage_events', function (Blueprint $table): void {
            $table->dropIndex('billing_usage_events_rolled_up_into_index');
        });
    }
};
