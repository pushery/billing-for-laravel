<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fill in the settlement link for charges that were settled one transaction at a time.
 *
 * `billing_merchant_charges.settlement_invoice_id` arrived for the COLLECTIVE settlement shape, because a
 * month-end document carries its transactions as lines and can name none of them in its header. The
 * per-transaction shape was left to the opposite direction — `settled_charge_reference` on the document —
 * and that is where the defect lived: one fact reachable two ways, so a reader walking from a charge found
 * only collective settlements and a reader walking from a document only single ones, with neither able to
 * tell which half it was missing.
 *
 * Both paths write the column now. Every row settled before that still reads null, and a figure that places
 * a withheld fee by the settlement document would then place the old rows by something else — the same
 * split, just moved from the writer into the data. So the old rows are filled from the documents that
 * already name them.
 *
 * ## Why this backfills where the neighboring column deliberately does not
 *
 * `purpose` on this table is nullable and NOT backfilled, and its migration explains why: the value would
 * have to be GUESSED, and the guess is wrong in the direction that matters. Nothing is guessed here. The
 * document states which charge it settled, in a column written at issue and frozen since; this migration
 * only walks a link that already exists in the other direction.
 *
 * ## What it refuses to match, and why each refusal is the safe direction
 *
 * A wrong link is worse than a missing one. A missing one leaves a fee placed by the money date, which is
 * today's behavior; a wrong one places it by a document that documents a DIFFERENT sale, and nothing about
 * the resulting figure looks unusual.
 *
 * - **A document naming no provider matches nothing.** A charge reference is unique only per provider, so
 *   on an installation with two drivers a reference alone can find the wrong row — the collision
 *   `SettlementCorrectionIssuer` was hardened against. Legacy documents from before the provider was frozen
 *   are exactly those rows, and the document query leaves them out by name. Without that filter they would
 *   still match nothing, but only by way of another table: the query builder turns `where('provider', null)`
 *   into `provider IS NULL`, and it is the NOT NULL constraint on the charges' `provider` column that makes
 *   that find no row. The behavior is pinned by an arm regardless, so a later change to either side that
 *   lets such a document match is noticed.
 * - **The merchant has to match as well.** The pair is unique in the table, so the risk is not two creators
 *   holding it; it is one creator's document naming another's charge. Without this a stranger's sale would
 *   read as settled by a document that never mentioned them.
 * - **A correcting document is skipped.** A correction copies `settled_charge_reference` from the original it
 *   reverses, so it names the same charge. The consideration is dated by the ORIGINAL settlement; placing a
 *   fee by the correction would date it to the reversal instead.
 * - **A charge that already has a link keeps it.** That link was written by a run that actually settled it.
 *   Ascending order plus this condition means the oldest matching document wins, which is the original.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('billing_invoices')
            ->whereNotNull('settlement_document_type')
            ->whereNotNull('settled_charge_reference')
            ->whereNotNull('provider')
            ->whereNull('correction_kind')
            ->select(['id', 'owner_type', 'owner_id', 'provider', 'settled_charge_reference'])
            ->orderBy('id')
            ->chunkById(500, function (Collection $documents): void {
                foreach ($documents as $document) {
                    DB::table('billing_merchant_charges')
                        ->whereNull('settlement_invoice_id')
                        ->where('merchant_type', $document->owner_type)
                        ->where('merchant_id', $document->owner_id)
                        ->where('provider', $document->provider)
                        ->where('charge_reference', $document->settled_charge_reference)
                        ->update(['settlement_invoice_id' => $document->id]);
                }
            });
    }

    /**
     * Deliberately nothing.
     *
     * Rolling this back would mean emptying the column, and after the fact a backfilled link is
     * indistinguishable from one a collective run wrote itself — the rollback would take real data with it.
     * The column stays nullable and the writers stay in place, so an installation that rolls this migration
     * back is left with more information than it started with and none that is wrong.
     */
    public function down(): void {}
};
