<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Contracts\CreatorTaxStatusResolver;
use Pushery\Billing\Enums\CreatorTaxStatus;
use Pushery\Billing\Enums\CreatorTaxStatusSource;
use Pushery\Billing\Events\CreatorPlacedOnTaxHold;
use Pushery\Billing\Events\CreatorTaxStatusChanged;
use Pushery\Billing\Models\CreatorTaxStatusRecord;

/**
 * The creator tax-status time series: the only place a status is RECORDED, and the only place the series is
 * interpreted — which interval governs a given date.
 *
 * It is not the only code that touches the table, and the sentence here used to say it was. Two sweeps go to
 * `CreatorTaxStatusRecord` directly: `SmallBusinessFlipSweep::governingRecords()` reads the series (its own
 * docblock says so on purpose — "read from the series rather than from a current-status column"), and
 * `LapsedAttestationSweep` both reads it and writes `hold_announced_at` on it.
 *
 * Neither is a violation: they touch a bookkeeping column and re-read intervals this class produced, and
 * neither splits or appends. But "the only reader" is the kind of claim someone builds on — it is exactly
 * what makes a change here look safe — so what this class actually owns is stated instead: the SPLIT rule
 * below, and the interpretation of which interval applies.
 *
 * Recording a status SPLITS the series rather than appending to it. A status recorded with a start date in
 * the past is the normal case, not an exception — somebody establishes in April what was already true in
 * January — and the interval that contained that date has to end there while everything after it stays
 * exactly as it was. Appending would leave two intervals claiming the same day, and which one a document
 * got would depend on row order.
 *
 * Reading always takes a moment. There is deliberately no "current status": every caller is producing a
 * document about a supply that happened at a particular time, and a status recorded today must not change
 * how a document from January reads.
 */
final readonly class CreatorTaxStatusLedger implements CreatorTaxStatusResolver
{
    public function __construct(private Dispatcher $events) {}

    public function statusAt(Model $merchant, CarbonImmutable $moment): CreatorTaxStatus
    {
        $at = Carbon::instance($moment);

        foreach ($this->seriesFor($merchant)->where('effective_from', '<=', $at)->orderByDesc('effective_from')->get() as $row) {
            if (! $row->covers($at)) {
                continue;
            }

            // An expired attestation is not a weaker standing, it is no standing. Answering here rather than
            // relying on a scheduled job to rewrite the series is the fail-safe half: a job that did not run
            // would otherwise leave a stale declaration reading as current, and it would read as a RECORDED
            // answer rather than as an assumption — the harder kind to notice.
            return $row->hasExpiredBy($at) ? CreatorTaxStatus::Unclarified : $row->status;
        }

        // No interval covers the moment — before the first record, or inside a deliberate gap. Not knowing
        // is a real state, and the one a payout hold keys off; inventing a friendlier answer here would put
        // a tax treatment on a document that nothing supports.
        return CreatorTaxStatus::Unclarified;
    }

    /**
     * Record a status from a moment onward.
     *
     * @param  ?CarbonImmutable  $attestedUntil  when the attestation goes stale, if it does. A status the
     *                                           system derived itself has no expiry clock.
     */
    public function record(
        Model $merchant,
        CreatorTaxStatus $status,
        CarbonImmutable $effectiveFrom,
        CreatorTaxStatusSource $source,
        ?string $evidenceRef = null,
        ?CarbonImmutable $attestedUntil = null,
        ?int $businessFoundedYear = null,
    ): CreatorTaxStatusRecord {
        $from = Carbon::instance($effectiveFrom);
        $previous = $this->statusAt($merchant, $effectiveFrom);

        $record = DB::transaction(function () use ($merchant, $status, $from, $source, $evidenceRef, $attestedUntil, $businessFoundedYear): CreatorTaxStatusRecord {
            // Close the interval that CONTAINS the new start, and only that one. Intervals that already
            // start later are untouched: a retroactive correction of January says nothing about March.
            $this->seriesFor($merchant)
                ->where('effective_from', '<', $from)
                ->where(static fn (Builder $query): Builder => $query->whereNull('effective_to')->orWhere('effective_to', '>', $from))
                ->update(['effective_to' => $from]);

            // The new interval runs until whatever already starts after it — not open-ended, or it would
            // swallow every later status the series already holds.
            $next = $this->seriesFor($merchant)
                ->where('effective_from', '>', $from)
                ->orderBy('effective_from')
                ->first();

            return CreatorTaxStatusRecord::query()->create([
                'merchant_type' => $merchant->getMorphClass(),
                'merchant_id' => $merchant->getKey(),
                'status' => $status,
                'effective_from' => $from,
                'effective_to' => $next?->effective_from,
                'source' => $source,
                'evidence_ref' => $evidenceRef,
                'business_founded_year' => $businessFoundedYear,
                'attested_until' => $attestedUntil instanceof CarbonImmutable ? Carbon::instance($attestedUntil) : null,
            ]);
        });

        // Announced only on a real change. The notice to the creator and the payout hold hang off this
        // event rather than off the write, so neither has to know where a status is stored — and neither
        // should fire when somebody re-records the status a creator already had.
        if ($previous !== $status) {
            $this->events->dispatch(new CreatorTaxStatusChanged(
                merchant: $merchant,
                previous: $previous,
                current: $status,
                effectiveFrom: $effectiveFrom,
                source: $source,
            ));

            // A consumer that wants to know "can this merchant sell" should not have to reimplement the
            // question from a status enum. A hold is reached two ways — this write, and an attestation
            // quietly running out — and an event that fired for only one of them would read, by its silence,
            // as "no hold". So the same event is dispatched here and by the sweep that watches the other
            // route, which is the condition under which a listener can trust it at all.
            if ($status->blocksSelling()) {
                $this->events->dispatch(new CreatorPlacedOnTaxHold($merchant, 'billing::tax_hold.status_recorded'));
            }
        }

        return $record;
    }

    /**
     * Every interval recorded for one creator, oldest first.
     *
     * @return list<CreatorTaxStatusRecord>
     */
    public function seriesOf(Model $merchant): array
    {
        return array_values($this->seriesFor($merchant)->orderBy('effective_from')->get()->all());
    }

    /** @return Builder<CreatorTaxStatusRecord> */
    private function seriesFor(Model $merchant): Builder
    {
        return CreatorTaxStatusRecord::query()
            ->where('merchant_type', $merchant->getMorphClass())
            ->where('merchant_id', $merchant->getKey());
    }
}
