<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which shape a routed sale had, and who it named as the seller — frozen onto the document.
 *
 * Both in one migration on purpose. They are one decision seen twice: the regime is how the books read, the
 * posture is who the receipt names, and a pair that disagrees issues a receipt and a settlement document
 * describing different transactions — each internally consistent, comparable only by somebody who thought
 * to compare them. Adding them separately would leave a window in which a row carries one and not the other.
 *
 * Both are NULLABLE, and null means "no regime was resolved for this row", not a shape. Rows written before
 * routed sales existed have no regime, and defaulting them to the commoner of the two would assert a
 * classification about transactions nobody classified — which is the same error as re-classifying one,
 * committed in bulk and without a decision.
 *
 * Server-only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('supply_regime')->nullable()->after('recipient_tax_status');
            $table->string('seller_posture')->nullable()->after('supply_regime');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn(['supply_regime', 'seller_posture']);
        });
    }
};
