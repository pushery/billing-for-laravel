<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT was sold, beside how much of it the merchant is owed.
 *
 * A routed charge records three amounts and the lane they took. It does not record what the buyer bought,
 * and a reader cannot tell a subscription cycle from a one-off purchase from a tip by looking at the row.
 * That is invisible while the share is transferred — a transfer needs an amount and a destination, nothing
 * more — and it becomes load-bearing the moment a consumer credits the share to a balance instead: a ledger
 * entry carries a type, and an append-only ledger with the wrong one cannot be corrected, only offset.
 *
 * ## Why a column rather than an argument on the seam
 *
 * The obvious fix is to hand the purpose to `MovesMerchantShare::transferShare()`, and it cannot be done.
 * That interface is implemented OUTSIDE this package — a consumer registers its own driver, which is the
 * whole reason the seam exists — and in PHP an implementation that declares fewer parameters than its
 * interface is a fatal error at class load, not a runtime failure. Measured on this machine rather than
 * assumed: adding one trailing optional parameter makes every existing driver fatal on `composer update`,
 * with no graceful path. The same argument the `MovesMerchantShare` docblock makes for why it is a separate
 * interface at all applies here one level down.
 *
 * So the fact goes where every other frozen fact about this sale already goes: onto the row, which any
 * driver can read from the source charge it is already given. `charge_reference` is unique per provider, so
 * that lookup is exact.
 *
 * ## Why nullable, and why it is not backfilled
 *
 * Null means "written before this was recorded", the same meaning `charge_type`, `seller_posture` and
 * `commission_tax_bps` give it on this table. Backfilling would have to guess, and the guess is wrong in the
 * one direction that matters: a tip persists nothing that names it, so it is only reachable by the ABSENCE
 * of a purchase and a subscription — and absence during a race reads a purchase whose rows have not landed
 * yet as a tip. A ledger typed from that guess is worse than one that declines to type old rows.
 *
 * ## Why it earns its place even where nobody credits a balance
 *
 * Self-billing and reporting ask the same question: this table is the record of what a merchant earned, and
 * until now it could not say what they earned it from.
 *
 * Server-only, reversible, additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->string('purpose')->nullable()->after('charge_type');
        });
    }

    public function down(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });
    }
};
