<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The money columns of the buyer-protection holds and the vouchers become 64-bit, like every other amount
 * the package stores.
 *
 * An amount is held in minor units, and in a currency with a small unit a 32-bit column runs out early:
 * 2,147,483,647 minor units of Indonesian rupiah are about 1,300 US dollars. Past that, PostgreSQL and MySQL
 * in strict mode refuse the row, so a hold over a sale of that size or a voucher of that value cannot be
 * stored at all, and MySQL without strict mode stores the column's maximum instead of the amount.
 *
 * Each column keeps its default; a changed column takes only what the change restates.
 *
 * Server-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_buyer_protection_holds', function (Blueprint $table): void {
            $table->bigInteger('charge_minor')->change();
            $table->bigInteger('platform_fee_minor')->default(0)->change();
            $table->bigInteger('seller_net_minor')->default(0)->change();
            $table->bigInteger('buyer_refund_minor')->default(0)->change();
        });

        Schema::table('billing_vouchers', function (Blueprint $table): void {
            $table->bigInteger('face_value_minor')->change();
            $table->bigInteger('remaining_minor')->change();
        });
    }

    public function down(): void
    {
        Schema::table('billing_buyer_protection_holds', function (Blueprint $table): void {
            $table->integer('charge_minor')->change();
            $table->integer('platform_fee_minor')->default(0)->change();
            $table->integer('seller_net_minor')->default(0)->change();
            $table->integer('buyer_refund_minor')->default(0)->change();
        });

        Schema::table('billing_vouchers', function (Blueprint $table): void {
            $table->integer('face_value_minor')->change();
            $table->integer('remaining_minor')->change();
        });
    }
};
