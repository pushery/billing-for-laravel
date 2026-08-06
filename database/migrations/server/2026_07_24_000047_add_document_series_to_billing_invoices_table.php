<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which numbering ROLE this document is — buyer receipt, self-billed invoice, settlement note, or commission
 * invoice (plus their correction series) — frozen onto the row.
 *
 * The role is what the belief "a document must match its sale" checks against: the commission chain never
 * emits a commission invoice, intermediation never settles a creator, and enforcing that at creation needs
 * the role recorded on the row the creation guard reads. The number prefix carries it too, but a prefix is a
 * jurisdiction's letter in config, and reversing a config map inside a boot guard is fragile — the enum
 * value is unambiguous and is what the code already branches on.
 *
 * Null is a document with no marketplace role (an ordinary single-seller invoice), so every existing row and
 * every single-seller row reads exactly as before and the role guard skips them. Scalar, so it is frozen in
 * the scalar loop of InvoiceRecord::booted() — reclassifying an issued document does not change a field, it
 * changes what the document claims to be. Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('document_series')->nullable()->after('settlement_document_type');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('document_series');
        });
    }
};
