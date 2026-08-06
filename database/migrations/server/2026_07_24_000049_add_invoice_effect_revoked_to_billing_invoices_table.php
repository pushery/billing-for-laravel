<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a creator's objection took away a self-billed document's effect as an invoice, and through which
 * channel it arrived — recorded BESIDE the frozen document, not on it.
 *
 * A self-billed invoice is one the platform wrote for the creator; the creator may object to any of them —
 * unconditionally, with no deadline — and on objection it stops being an invoice from the taxation period of
 * the objection forward (§ 14 Abs. 2, Abschn. 14.3 UStAE). That is the creator's own protection: a
 * mis-classified small business's immediate objection is exactly what saves them from a § 14c liability. The
 * document itself never changes (it stays frozen and retained); the objection is a state that lies next to it.
 *
 * Both columns are NULLABLE and are NOT in the frozen scalar loop of InvoiceRecord::booted() — unlike every
 * tax characteristic, this one is written AFTER the document is issued, because the objection arrives later.
 * Null is a document still in effect (every existing row and every single-seller row), so nothing reads
 * differently until an objection is recorded. The channel is kept as the evidence for "without delay" in a
 * § 14c case. Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->timestamp('invoice_effect_revoked_at')->nullable()->after('settlement_period');
            $table->string('invoice_effect_revoked_channel')->nullable()->after('invoice_effect_revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn(['invoice_effect_revoked_at', 'invoice_effect_revoked_channel']);
        });
    }
};
