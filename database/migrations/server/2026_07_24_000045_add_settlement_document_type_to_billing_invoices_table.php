<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which settlement document this row IS — a self-billed invoice or a plain settlement note — frozen onto it.
 *
 * A self-billed invoice and an ordinary one are different documents to a tax authority: the type code that
 * names a self-billed invoice (389) is not the one for an ordinary invoice (380), and emitting the wrong
 * one misstates what the document is. Until now every stored invoice was an ordinary one, so the kind was
 * never recorded; a self-billed document needs it recorded, and frozen like every other tax characteristic,
 * because reclassifying an issued document does not adjust a field — it changes what the document claims to
 * be.
 *
 * Null is an ordinary invoice, so every existing row and every single-seller row reads exactly as before.
 * Scalar, so it is frozen in the scalar loop of InvoiceRecord::booted(). Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('settlement_document_type')->nullable()->after('seller');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('settlement_document_type');
        });
    }
};
