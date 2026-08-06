<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The marker that keeps the daily payment reminder to ONE per day, per subscription.
 *
 * ## Why a date and not a flag
 *
 * The neighboring tax-hold sweep marks with `hold_announced_at`, a nullable timestamp, because that
 * announcement happens exactly once and never again. This one repeats every day of the cure window, so a
 * "already told them" flag would silence the second day onward. The column therefore records WHICH DAY the
 * reminder last went out, and the sweep sends when that day is not today.
 *
 * ## Why it sits on the subscription and not on the owner
 *
 * Arrears are per relationship. A marker on the customer would let the reminder for one merchant suppress
 * the reminder for another on the same day — the same defect as a platform-wide lockout, one level down and
 * harder to notice, because the customer does get a message and only the second one is missing.
 *
 * Nullable and additive: an existing row has never been reminded, which is exactly what null means here, so
 * nothing needs backfilling and a single-seller install is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->date('payment_reminded_on')->nullable()->after('dunning_level');
        });
    }

    public function down(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('payment_reminded_on');
        });
    }
};
