<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pending plan change that has not taken effect yet — a downgrade scheduled to the period end.
 *
 * An upgrade takes effect immediately and needs nothing stored. A downgrade waits until the current cycle
 * the customer already paid for runs out, so the target tier and the moment it applies are held here until
 * then. Both columns are nullable and null together: a subscription with no pending change carries neither.
 *
 * Server-only, additive, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->string('scheduled_tier_key')->nullable()->after('tier_key');
            $table->timestamp('scheduled_swap_at')->nullable()->after('scheduled_tier_key');
        });
    }

    public function down(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['scheduled_tier_key', 'scheduled_swap_at']);
        });
    }
};
