<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which way the odd minor unit went when this sale was priced.
 *
 * The document side has frozen this since it existed — `commission_residual` sits on the settlement and a
 * correction reads it back. The money side read whatever the installation is configured for TODAY, so a
 * charge made before somebody changed the setting was reconstructed in the opposite direction from the
 * document correcting it. Two truths about the same cent, on every uneven split.
 *
 * It matters because a clawback is not a fresh split. It is a DIFFERENCE against the original —
 * `merchantHolds − payoutOnRemainder` — and if the two halves round the other way from each other, the
 * difference carries the rounding error of both.
 *
 * Nullable, because every existing row predates it: those fall back to the configured direction, which is
 * the closest thing to the truth still available and a far better guess than a constant. What they must NOT
 * fall back to is the enum's own default, which is a value nobody chose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->string('fee_residual', 16)->nullable()->after('fee_flat_minor');
        });
    }

    public function down(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->dropColumn('fee_residual');
        });
    }
};
