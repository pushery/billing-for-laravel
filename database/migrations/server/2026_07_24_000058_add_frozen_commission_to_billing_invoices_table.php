<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The commission terms this settlement was priced with.
 *
 * A correction recomputes the merchant's share on what REMAINS of the sale — that is the whole reason it is
 * not a proportion — and to do that it needs the terms the sale was priced under. Read from configuration at
 * correction time it would use whatever the rate says today, so a platform that lowered its rate would
 * quietly reduce every historical clawback, and one that raised it would over-collect. Neither shows up
 * anywhere: the document still adds up, it just describes a sale nobody made.
 *
 * Both parts, because they behave differently on a partial refund: the rate scales with the remainder and the
 * fixed part does not — a handling fee is charged once, not halved by a half refund. Storing only the
 * resulting amount would lose exactly that distinction, which is the one the recomputation exists for.
 *
 * Null on every document priced with no commission — a fan receipt, a single-seller invoice — so nothing on
 * the single-seller path changes. Frozen with the rest of the sale's shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->unsignedInteger('commission_bps')->nullable()->after('settled_charge_reference');
            $table->unsignedBigInteger('commission_flat_minor')->nullable()->after('commission_bps');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn(['commission_bps', 'commission_flat_minor']);
        });
    }
};
