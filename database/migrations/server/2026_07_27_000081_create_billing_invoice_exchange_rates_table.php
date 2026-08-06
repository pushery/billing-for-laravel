<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rates a document was actually converted at, frozen onto it.
 *
 * ## Rows rather than columns, and why
 *
 * One sale carries more than one euro figure, lawfully: the document takes the ministry's monthly average,
 * the one-stop-shop return takes the central bank's rate at the period end and expressly excludes monthly
 * averages, and the payout is whatever the money moved at. Three layers today, and the count is not fixed —
 * a jurisdiction with its own reporting rule adds a fourth.
 *
 * As columns that would be five fields times however many layers, bolted onto an invoice table that is
 * already wide, and a fourth layer would be a migration on the busiest table in the schema. As rows it is
 * one table, one row per layer, and a new layer is an enum case.
 *
 * ## Append-only, like the audit ledger
 *
 * A frozen rate that can be updated is not frozen. The model refuses an update outright rather than listing
 * which columns are protected: there is no field here that may legitimately change after the document was
 * issued, so the guard is the whole row.
 *
 * Deleting follows the invoice — a document that no longer exists has no conversions to defend — and
 * nothing else may delete one.
 *
 * Server-only, reversible, and empty on a single-currency install: a sale that never converted has no rate
 * to freeze, and nothing writes a trivial 1.0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_invoice_exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('billing_invoices')->cascadeOnDelete();

            // Which conversion of this sale. See ExchangeRateLayer.
            $table->string('layer');

            // The pair as the publisher states it, never inverted — the same rule the rate store follows.
            $table->char('from_currency', 3);
            $table->char('to_currency', 3);
            $table->bigInteger('rate_scaled');

            // The date the PUBLISHER stated, and the rule that made this the correct rate. Both travel with
            // the number because "which rate did you use" is the easy question and "why was that the right
            // rate" is the one an audit asks first.
            $table->date('rate_date');
            $table->string('basis');
            $table->string('source');

            $table->timestamps();

            // One rate per layer per document. A second row for the same layer would mean a document with
            // two answers to the same question, and nothing downstream could choose between them.
            $table->unique(['invoice_id', 'layer'], 'billing_invoice_exchange_rates_invoice_layer_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoice_exchange_rates');
    }
};
