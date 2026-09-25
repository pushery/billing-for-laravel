<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The seats a subscription the local engine bills is paying for.
 *
 * A provider that bills seats keeps the quantity on its own subscription item. The local engine has no such item,
 * so the quantity lives on the row, with what it needs to bill a change in the middle of a period by the day.
 *
 * ## Why three columns and no history table
 *
 * The engine bills a period when it closes. A change before then has to count the old quantity for the days it
 * held and the new one for the rest, and that needs only a running total: `seat_days_accrued` holds the seat-days
 * of the period up to the last change, and `seat_quantity_since` when the current quantity began. The cycle adds
 * the days the current quantity has held and starts the next period at zero.
 *
 * ## Why the quantity is nullable
 *
 * Null is a subscription that bills no seats, which is every row written before this existed and every row whose
 * application never names membership events. It bills one unit of the tier price, exactly as before.
 *
 * ## Why there is no index
 *
 * Nothing looks a subscription up BY its seats. They are read off a row already found by its owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->unsignedInteger('seat_quantity')->nullable();
            $table->timestamp('seat_quantity_since')->nullable();
            $table->unsignedBigInteger('seat_days_accrued')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['seat_quantity', 'seat_quantity_since', 'seat_days_accrued']);
        });
    }
};
