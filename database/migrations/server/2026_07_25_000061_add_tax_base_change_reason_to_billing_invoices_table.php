<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why the taxable amount changed — money given back, or money that will not arrive.
 *
 * The two produce identical figures in identical periods, so nothing in the numbers can tell them apart
 * afterwards. What separates them is the future: repaid is final, uncollectible is a judgement that a later
 * payment overturns, and the correction then has to be corrected back. Without the reason on the document
 * there is no way to know which corrections are still open, and a receipt against a written-off sale looks
 * like ordinary income instead of the reversal it is.
 *
 * Null on every document that corrects nothing, which is every document a single-seller install writes today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('tax_base_change_reason')->nullable()->after('correction_kind');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('tax_base_change_reason');
        });
    }
};
