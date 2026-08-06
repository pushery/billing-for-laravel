<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which side of an uneven commission split kept the leftover minor unit.
 *
 * The rate and the fixed part were already frozen on the settlement, for the reason the column beside them
 * states: recomputing them at correction time would use whatever configuration says that day. The direction
 * of the rounding is the same kind of fact and was not frozen, so a correction reconstructed it from a
 * hardcoded default — and on an installation that hands the odd cent the other way, the correction came back
 * one cent off the sale it was correcting. Small, silent, and on every uneven split.
 *
 * Null on every document written before this existed, and on every single-seller document, where there is no
 * commission to split at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('commission_residual', 32)->nullable()->after('commission_flat_minor');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('commission_residual');
        });
    }
};
