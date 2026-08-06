<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Pushery\Billing\Models\CreatorTaxStatusRecord;

/**
 * Goes looking for the creators who have outgrown their size relief, because nothing else will.
 *
 * ## Why a sweep, and why this one DECIDES where its sibling only tells
 *
 * `LapsedAttestationSweep` is the shape this follows, and the difference is worth stating out loud: that one
 * announces a hold that already began, this one **changes a creator's tax standing**. A bug there is a
 * message nobody needed; a bug here is a wrong tax treatment on real documents, in either direction.
 *
 * It exists for the same reason the other does — the event nobody can observe. A creator crossing a turnover
 * limit writes no row anywhere. Enough sales accumulate and a threshold is simply past, which means the
 * moment the flip should have happened is a moment nothing dispatched. Only something that goes looking
 * finds it.
 *
 * ## What it does NOT decide
 *
 * The asymmetry belongs to {@see SmallBusinessAutoFlip} and this sweep does not soften it: a count that
 * stays under a limit proves nothing, because the platform only ever sees what was sold HERE. So this can
 * flip a creator to standard rating and can never flip one back.
 *
 * It also does not re-implement who is eligible. Every merchant with any recorded standing is handed to the
 * flip, which refuses on its own for anyone not resting on a size relief. A pre-filter here would be a
 * second copy of that rule, and the two would eventually disagree.
 *
 * ## The founding year is REPORTED when missing, never substituted
 *
 * `business_founded_year` is nullable, and the limit that applies in a business's founding year is a
 * different, much lower one — 2.5 million against 10 million in the shipped German figures. Passing a
 * substitute year would be inventing a fact about somebody's business; passing null is honest but applies
 * the higher limit, which fails in the direction that keeps issuing tax-free documents to a creator who has
 * outgrown the relief.
 *
 * Neither is silent. The sweep reconciles what it can — the prior-year check needs no founding year at all —
 * and returns the creators whose current-year check ran without one, so the gap is somebody's inbox rather
 * than a number that quietly never moved.
 */
final readonly class SmallBusinessFlipSweep
{
    public function __construct(
        private Repository $config,
        private CreatorTaxStatusLedger $ledger,
        private SmallBusinessAutoFlip $flip,
        private SmallBusinessThresholdMonitor $monitor,
    ) {}

    /** Reconcile every creator with a recorded standing against the turnover they have run through here. */
    public function reconcile(CarbonImmutable $now): SmallBusinessFlipSweepReport
    {
        $currency = $this->currency();
        $at = Carbon::instance($now);

        $examined = 0;
        $withoutFoundingYear = [];
        $approaching = [];

        foreach ($this->governingRecords($at) as $record) {
            $merchant = $record->merchant;

            if ($merchant === null) {
                // Erased, or the host model is gone. There is no creator left to be standard rated, and
                // writing a status line for a subject nobody can resolve would only make the series lie.
                continue;
            }

            if (! $this->ledger->statusAt($merchant, $now)->reliesOnSizeRelief()) {
                continue;
            }

            $examined++;

            if ($record->business_founded_year === null) {
                $withoutFoundingYear[] = $record->merchant_type.'#'.$record->merchant_id;
            }

            $this->flip->reconcile($merchant, $currency, $now->year, $record->business_founded_year);

            // Asked AFTER the flip, deliberately. A creator who just crossed the limit is standard rated
            // now, and warning them that they are nearing a threshold they have already passed would be
            // the wrong message on the one day it matters most.
            if ($this->ledger->statusAt($merchant, $now)->reliesOnSizeRelief()) {
                $level = $this->monitor->approachingLevel($merchant, $currency, $now->year, $record->business_founded_year);

                if ($level !== null) {
                    $approaching[$record->merchant_type.'#'.$record->merchant_id] = $level;
                }
            }
        }

        return new SmallBusinessFlipSweepReport($examined, $withoutFoundingYear, $approaching);
    }

    /**
     * The one interval answering for each creator right now, newest first per creator.
     *
     * Read from the series rather than from a current-status column, because there is no such column and
     * deliberately so: a document asks what a standing WAS on the day of a supply, and one overwritable
     * value cannot answer that.
     *
     * @return list<CreatorTaxStatusRecord>
     */
    private function governingRecords(Carbon $at): array
    {
        $governing = [];

        $records = CreatorTaxStatusRecord::query()
            ->whereNotNull('merchant_type')
            ->whereNotNull('merchant_id')
            // An index-friendly pre-filter and nothing more. What makes a future standing not count is
            // `covers()` below, which is also true of the rows this clause happens to drop -- stated because
            // deleting this line changes no answer, and a reader who mistook it for the RULE would then be
            // free to move `covers()` instead.
            ->where('effective_from', '<=', $at)
            ->orderBy('effective_from')
            ->get();

        foreach ($records as $record) {
            if (! $record->covers($at)) {
                continue;
            }

            // Ordered oldest first, so the last one written per creator is the newest that still covers now.
            //
            // In a healthy series exactly one interval covers a moment, and this overwrite never fires. It
            // is here for the series that is NOT healthy: nothing at the database level forbids two
            // overlapping intervals -- the unique key is on the start date -- so a data repair or a
            // hand-written row can produce a pair. Reading whichever came back first would then reconcile a
            // creator against a standing they have already replaced, and the run would look perfectly normal.
            $governing[$record->merchant_type.'#'.$record->merchant_id] = $record;
        }

        return array_values($governing);
    }

    private function currency(): string
    {
        $currency = $this->config->get('billing.currency');

        // Defensive for the same reason every other currency read here is: a misconfigured value must not
        // scope a turnover count to an empty string, which would count nothing and report every creator as
        // comfortably under their limit.
        return is_string($currency) && $currency !== '' ? $currency : 'EUR';
    }
}
