<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provider can finalize a NEGATIVE-total invoice — a steep mid-cycle downgrade whose proration credit
 * exceeds the new charge yields an invoice with a negative total. The money columns were declared
 * unsignedBigInteger, which diverges by engine: MySQL 8.4 in strict mode (Laravel Cloud's default) rejects
 * the negative with SQLSTATE 22003, so the effect throws and the credit invoice is never persisted — the
 * credit silently vanishes from the books — while Postgres and SQLite (no unsigned) store it. Making the
 * magnitude columns signed stores a negative invoice faithfully and identically on every engine.
 *
 * Only the amount columns change; ids (credited_invoice_id) stay unsigned. Postgres has no unsigned integer
 * type, so there the change is a no-op; on MySQL it drops the UNSIGNED attribute.
 *
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Only the total and net can go negative (a credit invoice); the tax is floored to >= 0 by the
        // mapper, so tax_minor stays unsigned and keeps expressing that a VAT amount is never negative.
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->bigInteger('total_minor')->change();
            $table->bigInteger('subtotal_minor')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('total_minor')->change();
            $table->unsignedBigInteger('subtotal_minor')->nullable()->change();
        });
    }
};
