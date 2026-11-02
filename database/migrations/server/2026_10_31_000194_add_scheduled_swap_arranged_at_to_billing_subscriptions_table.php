<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Billing\Support\MigrationLocks;

/**
 * When a plan change scheduled for the end of the period was arranged at the provider.
 *
 * A provider that renews a subscription on its own renews it at the moment the period ends, so a change applied after
 * that moment comes after an invoice for another period of the old plan. The scheduled change is therefore arranged at
 * the provider a few days before it comes due, and the provider makes it at the boundary itself. When it comes due,
 * only the schedule on the row is cleared. Null where the change was not arranged ahead, which is then applied when due.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dateTime('scheduled_swap_arranged_at')->nullable();
        });
    }

    public function down(): void
    {
        MigrationLocks::bound();

        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('scheduled_swap_arranged_at');
        });
    }
};
