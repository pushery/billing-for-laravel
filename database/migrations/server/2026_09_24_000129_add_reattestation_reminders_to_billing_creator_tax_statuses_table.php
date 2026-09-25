<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a creator was told that their declaration is due, and when they were told it runs out.
 *
 * A declaration is a statement about a year in progress and expires after the year boundary, once the
 * grace period has passed. Without these two reminders the first the creator hears of it is the hold,
 * after the date has already passed.
 *
 * Markers beside the series, like `hold_announced_at`: whether somebody has been told is a fact about
 * the telling, not about what was declared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_creator_tax_statuses', function (Blueprint $table): void {
            $table->timestamp('reattestation_due_announced_at')->nullable()->after('hold_announced_at');
            $table->timestamp('expiry_reminded_at')->nullable()->after('reattestation_due_announced_at');
        });
    }

    public function down(): void
    {
        Schema::table('billing_creator_tax_statuses', function (Blueprint $table): void {
            $table->dropColumn(['reattestation_due_announced_at', 'expiry_reminded_at']);
        });
    }
};
