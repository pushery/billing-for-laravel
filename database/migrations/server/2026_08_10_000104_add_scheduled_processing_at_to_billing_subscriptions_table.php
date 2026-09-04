<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a local-engine driver should next act on a subscription.
 *
 * A provider-driven driver has nothing to store here: Stripe decides when the next charge falls and says
 * so. A driver whose engine is local has nobody to ask, and this column is the answer — the one row
 * `billing:run` selects on.
 *
 * It is deliberately NOT a reading of `current_period_end`, though on the happy path the two agree. They
 * have to be free to disagree: a failed charge is retried inside the period it belongs to, and a dunning
 * ladder that pushes the next attempt out would otherwise have to move `current_period_end` with it —
 * silently re-dating the service the customer is paying for, on the row every invoice and usage bucket
 * reads. One column answers "what did they buy", the other "when do we next try".
 *
 * Nullable, because most rows never have one: every Stripe subscription in an existing install, and every
 * local subscription that is not currently scheduled (a trial before its first charge, a paused row). The
 * due query treats NULL as "not scheduled" rather than as "overdue", which is the safe reading — the other
 * one would charge every subscription in the table on the first run after this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->timestamp('scheduled_processing_at')->nullable()->after('current_period_end');

            // The due query is `WHERE scheduled_processing_at <= now AND status IN (...)`, and it runs on a
            // schedule against the whole table. Indexed in that order so the range narrows first.
            $table->index(['scheduled_processing_at', 'status'], 'billing_subscriptions_due_index');
        });
    }

    public function down(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table): void {
            $table->dropIndex('billing_subscriptions_due_index');
            $table->dropColumn('scheduled_processing_at');
        });
    }
};
