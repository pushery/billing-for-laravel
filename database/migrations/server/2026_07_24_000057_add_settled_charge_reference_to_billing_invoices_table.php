<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which routed transaction a settlement document settles.
 *
 * Without it a settlement is unreachable from the sale it belongs to: a refund knows the charge, and the
 * correction it owes has to find the document issued for that charge — by matching amounts and dates, which
 * is a guess, or not at all. Both of those are worse than a column.
 *
 * Deliberately NOT `provider_id`, which already exists and means the provider's id for THIS document. A
 * settlement is numbered by the platform and has no provider document behind it; overloading that column
 * would make one field mean two things depending on who wrote the row, and the reader could not tell which.
 *
 * Null on every document that settles no routed transaction — a fan receipt, a single-seller invoice — so
 * the single-seller path writes exactly what it wrote before. Indexed because the lookup direction is
 * charge-to-document, which is the direction a refund arrives from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('settled_charge_reference')->nullable()->after('correction_kind');
            $table->index('settled_charge_reference');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropIndex(['settled_charge_reference']);
            $table->dropColumn('settled_charge_reference');
        });
    }
};
