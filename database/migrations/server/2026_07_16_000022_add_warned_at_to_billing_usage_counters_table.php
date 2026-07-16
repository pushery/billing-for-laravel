<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the owner was warned that this meter is running out, for this period — the once-per-period claim
 * behind the quota-warning notice.
 *
 * It lives on the counter row rather than in a separate ledger because the counter already IS the unique
 * (owner, meter, period) row, which makes the claim a single conditional UPDATE: whoever flips this from
 * NULL wins and sends the notice. Two requests crossing the threshold at the same instant therefore warn
 * the customer once, not twice — and the next period starts on a fresh row, so they are warned again when
 * it matters again.
 *
 * Server-only. Not indexed: it is only ever read for a row already located by the counter's unique key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_usage_counters', function (Blueprint $table): void {
            $table->timestamp('warned_at')->nullable()->after('reserved');
        });
    }

    public function down(): void
    {
        Schema::table('billing_usage_counters', function (Blueprint $table): void {
            $table->dropColumn('warned_at');
        });
    }
};
