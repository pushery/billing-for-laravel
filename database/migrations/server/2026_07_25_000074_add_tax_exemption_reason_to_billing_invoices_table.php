<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why this supply carried no tax — frozen, because a zero cannot say it afterwards.
 *
 * The tax calculator returns the same `Money::zero` for a supply the buyer accounts for and for one placed
 * outside the union. Those are different statements on a document, different EN 16931 categories, and
 * different lines in a return — and once only the amount is stored, nothing can tell them apart again. The
 * document is what a reader validates years later, so the reason has to be on it rather than re-derived
 * from a situation that has since moved.
 *
 * Deliberately NOT derived from the columns already here, although it looks like it could be:
 * `tax_archetype` gives goods-versus-services and `destination_country` gives the destination. But
 * `destination_country` is nullable and only populated in the one-stop-shop path — on an ordinary invoice
 * it is null. An export would therefore present as rate zero, no destination, no exemption: indistinguishable
 * from a zero-rated domestic supply. A derivation that goes quiet in exactly the case it exists for is not a
 * derivation.
 *
 * Nullable, and that nullability is the honest answer for every row written before this column existed:
 * those documents recorded no reason, so the migration does not invent one for them. Backfilling a plausible
 * value would put a legal claim on documents that never made it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('tax_exemption_reason', 32)->nullable()->after('tax_exempt');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn('tax_exemption_reason');
        });
    }
};
