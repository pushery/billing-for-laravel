<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the tax law treated as the seller on a routed sale, frozen onto the row that recorded it.
 *
 * The small-business turnover depends on it. Under the commission chain the creator supplies the platform,
 * and what that supply was worth is the payout. As a seller of record or through a disclosed intermediary
 * the creator supplies the buyer, and the platform's commission is a service bought from the platform: it is
 * not deducted from what the buyer paid for the creator's own supply (CJEU C-18/92 Bally). The counters used
 * to read the payout for every sale, and so under-counted every creator who supplies the buyer by the whole
 * commission, in the direction that takes them out of the small-business regime too late.
 *
 * ## Why nullable
 *
 * Null is "written before this was recorded". A reader counts such a row under the installation's configured
 * posture, which is right for every installation that never changed it.
 *
 * Server-only, reversible, additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->string('seller_posture', 32)->nullable()->after('charge_type');
        });
    }

    public function down(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->dropColumn('seller_posture');
        });
    }
};
