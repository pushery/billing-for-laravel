<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A merchant share that was not moved because the merchant's payouts were withheld, and why.
 *
 * Separate from `transfer_failed_at` on purpose. A failed transfer is something to retry and to count as a
 * fault; a withheld one is a decision, and it moves when the reason for it ends or when the money rail's own
 * limit is reached. The marker stays after the share has moved, as the record that it waited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->timestamp('transfer_withheld_at')->nullable()->after('transfer_failed_at');
            $table->string('transfer_withheld_reason')->nullable()->after('transfer_withheld_at');
        });
    }

    public function down(): void
    {
        Schema::table('billing_merchant_charges', function (Blueprint $table): void {
            $table->dropColumn(['transfer_withheld_at', 'transfer_withheld_reason']);
        });
    }
};
