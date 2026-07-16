<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document-level E-invoicing text fields, frozen on the issued invoice:
 *  - buyer_reference — the buyer's routing reference (EN 16931 BT-10). For a German B2G supply this carries
 *    the Leitweg-ID; XRechnung REQUIRES it, and a conformant validator rejects a B2G document without it.
 *  - vat_note — the human-readable VAT exemption / reverse-charge reason text (EN 16931 BT-120), e.g.
 *    "Steuerschuldnerschaft des Leistungsempfängers". It pairs with the reverse_charge marker: the boolean
 *    drives the machine VAT category (AE), this note is the wording a reader and the validator expect beside it.
 *
 * Both nullable — not every invoice is B2G or tax-exempt. Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('buyer_reference')->nullable()->after('reverse_charge');
            $table->string('vat_note')->nullable()->after('buyer_reference');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn(['buyer_reference', 'vat_note']);
        });
    }
};
