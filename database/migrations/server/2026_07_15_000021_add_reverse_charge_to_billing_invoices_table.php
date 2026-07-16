<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the invoice is an intra-EU B2B reverse-charge supply — the buyer, not the seller, accounts for
 * the VAT. It is a frozen fact of the issued document (the tax treatment must not change after issue), so
 * it lives on the immutable row: the EN 16931 / XRechnung renderer reads it to emit VAT category `AE` with
 * an exemption reason instead of the wrong zero-rated `Z`, which a conformant validator rejects.
 *
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->boolean('reverse_charge')->default(false)->after('tax_minor');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('reverse_charge');
        });
    }
};
