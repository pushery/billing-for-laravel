<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the provider actually moved to the merchant, beside what the row says they were owed.
 *
 * The two are not the same question and the package only ever recorded the second. `transferShare()` answers
 * with the amount that moved; the caller took the reference off that answer and dropped the rest. A journal
 * built that way cannot disagree with itself — which sounds like consistency and is the opposite: the
 * reconciliation against the provider that this milestone promises had nothing to compare.
 *
 * ## Why it also matters after the sale
 *
 * `reversibleMinor()` is the ceiling on clawing money back from a merchant, and it was read off what was
 * OWED. Where the provider moved less, the package would try to reverse money that never reached them — and
 * on a lost dispute the platform is the one out of pocket for the difference.
 *
 * ## Why nullable rather than defaulted to the net
 *
 * Null is "nobody reported a figure": every row written before this migration, and every destination charge,
 * where the provider moves the share as part of the payment and no transfer call is ever made. Defaulting it
 * to the net would state a provider figure nobody received — the exact failure this column exists to end,
 * reintroduced as a default.
 *
 * Server-only, reversible, additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->unsignedBigInteger('transfer_moved_minor')->nullable()->after('net_minor');
        });
    }

    public function down(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->dropColumn('transfer_moved_minor');
        });
    }
};
