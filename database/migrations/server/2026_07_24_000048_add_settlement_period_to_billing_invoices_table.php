<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which billing period a COLLECTIVE settlement document settles — `YYYY-MM` for a monthly run — frozen onto
 * the row.
 *
 * A monthly collective self-billing document is one document per creator and month; running the settlement
 * again for the same month must find the existing document rather than mint a second one. The period is what
 * that idempotency keys on, and it also makes the document self-describing: a per-transaction document is
 * dated on its own supply day and could land on a month-end, so the date alone cannot distinguish a
 * collective document from a same-day single one — the period marker can.
 *
 * Null is any non-collective document (a per-transaction settlement, an ordinary invoice, a single-seller
 * row), so every existing row reads exactly as before and the collective idempotency query skips them.
 * Scalar, so it is frozen in the scalar loop of InvoiceRecord::booted() — a document settles a fixed period
 * and never a different one. Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('settlement_period')->nullable()->after('document_series');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('settlement_period');
        });
    }
};
