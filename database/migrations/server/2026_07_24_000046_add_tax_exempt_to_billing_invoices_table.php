<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the supply on this document is EXEMPT — frozen, because it decides how the document reads to a
 * tax authority.
 *
 * A tax-free supply is not one thing. An exempt supply (a small business, § 19) is EN 16931 category E and
 * carries an exemption reason; a supply whose tax is merely withheld pending validation is a taxable one
 * not yet stated, and a genuinely zero-rated supply is category Z. All three show no tax, so the document
 * alone cannot tell them apart — only the tax decision that produced it can, and it records the answer here.
 *
 * Null/false is the ordinary case (a stated tax, a reverse charge, a withheld or zero-rated supply), so
 * every existing row and every single-seller row reads exactly as before. Scalar, so it is frozen in the
 * scalar loop of InvoiceRecord::booted() — reclassifying an issued document does not change a field, it
 * changes what the document claims. Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->boolean('tax_exempt')->default(false)->after('reverse_charge');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('tax_exempt');
        });
    }
};
