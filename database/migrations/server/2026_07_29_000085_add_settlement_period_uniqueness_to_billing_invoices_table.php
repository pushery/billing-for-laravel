<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One document per owner, series and period — enforced by the database rather than by a lookup.
 *
 * ## Why the lookup is not enough
 *
 * Both period-billing paths avoid duplicates the same way: read whether a document exists for this period,
 * and write one if it does not. That is check-then-act, and it is correct exactly as long as nothing else
 * is doing it at the same moment. Payment events are redelivered — that is the normal case these paths are
 * built for — and two deliveries arriving together both find nothing and both write. The second document
 * draws its own number from the running series, so the duplicate is not merely a repeated row: it is a
 * numbered document that a return then counts twice.
 *
 * The window is small and the consequence is not, which is the combination that makes it worth a constraint
 * instead of a comment.
 *
 * ## Why the series is part of the key
 *
 * A creator's settlement and a buyer's receipt can name the same owner and the same period and are two
 * different documents. Keying on owner and period alone would refuse the second one.
 *
 * ## Why nulls are the point rather than a loophole
 *
 * A one-off purchase covers no stretch of time and carries no period. Every engine this package supports
 * treats nulls as distinct in a unique index, so those rows never collide with each other — which is the
 * behavior wanted here, not a gap being tolerated. Only rows that actually claim a period are constrained.
 *
 * ## What this can refuse on an existing installation
 *
 * A database that already holds two documents for one owner, series and period. That is the defect this
 * prevents, so the migration surfacing it is the point — but it surfaces at deploy time, and the duplicates
 * have to be resolved (one of them credited or removed) before it will apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->unique(
                ['owner_type', 'owner_id', 'document_series', 'settlement_period'],
                'billing_invoices_owner_series_period_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropUnique('billing_invoices_owner_series_period_unique');
        });
    }
};
