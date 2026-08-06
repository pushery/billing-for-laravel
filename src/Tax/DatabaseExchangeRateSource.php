<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Override;
use Pushery\Billing\Contracts\ExchangeRateSource;
use Pushery\Billing\Enums\ExchangeRateBasis;
use Pushery\Billing\Exceptions\ExchangeRateUnavailable;
use Pushery\Billing\Models\ExchangeRateRecord;

/**
 * Reads rates a consumer has already imported. It never fetches, and it never computes one.
 *
 * ## Why reading is a separate thing from importing
 *
 * A payment must not wait on somebody else's uptime, and a rate for a past date does not change — so there
 * is nothing a live call would buy on the critical path of a sale except a way to fail. The importers run
 * on a schedule and write rows; this reads them. If a period was never imported, the answer is a refusal,
 * which is the whole point: the two tempting substitutes, a zero and the nearest available rate, both put a
 * plausible figure on a tax document and neither announces itself.
 *
 * ## The two lookups, because the rules are two different shapes
 *
 * A **monthly average** covers a month, so the day asked for selects a month and the row for that month
 * answers. Nothing is resolved forward: a month either has an announced average or it does not, and the
 * next month's average is not a late answer for this one.
 *
 * A **central-bank rate** belongs to a trading day, and the day asked for is frequently not one. Weekends
 * and holidays have no observation at all, and the law says so: where no rate was published, the next
 * publication day applies. So the query takes the earliest row at or after the date asked for.
 *
 * **Forward only, and bounded.** Backwards would be inventing a rate for a day the bank had not reached
 * yet. Unbounded forwards would mean a series that stops in March quietly answers a December booking with
 * March's last rate — an answer that is wrong by nine months of currency movement and looks exactly like a
 * correct one. The bound is a fortnight, which is far more than any real closure (the longest run of shut
 * days at a major central bank is the turn of the year, and it is days rather than weeks) and far less than
 * a gap that means the series was never imported.
 */
final readonly class DatabaseExchangeRateSource implements ExchangeRateSource
{
    /**
     * How far a central-bank lookup may walk forward to find the next publication day.
     *
     * Sized to cover a closure, not a gap. See the class docblock for why an unbounded walk is the dangerous
     * direction: it turns "this series was never imported" into a confidently wrong number.
     */
    public const int FORWARD_LIMIT_DAYS = 14;

    #[Override]
    public function rateFor(string $from, string $to, CarbonImmutable $on, ExchangeRateBasis $basis): FrozenExchangeRate
    {
        $record = $basis === ExchangeRateBasis::CentralBankMonthlyAverage
            ? $this->monthlyAverage($from, $to, $on, $basis)
            : $this->nextPublished($from, $to, $on, $basis);

        if (! $record instanceof ExchangeRateRecord) {
            throw ExchangeRateUnavailable::forConversion($from, $to, $on->toDateString(), $basis->value);
        }

        return new FrozenExchangeRate(
            $from,
            $to,
            $record->rate_scaled,
            // The publisher's date, taken off the row rather than off the request. For a resolved-forward
            // central-bank rate these genuinely differ, and the frozen rate has to carry the day the rate
            // was actually published for -- that is the day a reviewer will look it up under.
            CarbonImmutable::parse($record->rate_date->toDateString()),
            $record->source,
            $basis,
        );
    }

    /** The announced average for the month the date falls in. Stored against the month's first day. */
    /**
     * The month's average, COMPUTED from the daily series rather than looked up as a row of its own.
     *
     * It used to query for a row carrying this basis, and nothing has ever written one: `ExchangeRateImport`
     * stores the daily series under the two central-bank-at-a-date rules only. So a German domestic
     * conversion — which the profile hands exactly this basis — asked for data that could not exist and threw
     * `ExchangeRateUnavailable` every time. The lookup was written for a ministry importer that was never
     * buildable: the table is published behind a page that refuses automated retrieval, and it is an
     * aggregation of these same daily rates in the first place.
     *
     * So the average is taken here, from the observations already imported. The arithmetic mean of the
     * month's published reference rates is what the ministry table itself is, and computing it from the
     * source removes a fetch that could not be automated rather than reproducing it.
     *
     * A month with no published day is still `null` — fail-loud, not an average of nothing. A partial month
     * averages what was published, which is what an average of a series with holidays in it means.
     */
    private function monthlyAverage(string $from, string $to, CarbonImmutable $on, ExchangeRateBasis $basis): ?ExchangeRateRecord
    {
        // The daily series, under the rule the importer actually writes. Asking under `$basis` would ask for
        // the rows whose absence is the defect this method exists to close.
        $days = $this->pair($from, $to, ExchangeRateBasis::CentralBankAtTaxPoint)
            ->whereDate('rate_date', '>=', $on->startOfMonth()->toDateString())
            ->whereDate('rate_date', '<=', $on->endOfMonth()->toDateString())
            ->orderBy('rate_date')
            ->get();

        if ($days->isEmpty()) {
            return null;
        }

        $scaled = $days->map(static fn (ExchangeRateRecord $day): int => $day->rate_scaled);
        $average = (int) round(array_sum($scaled->all()) / $scaled->count());

        // A record rather than a bare number, so the caller's freeze carries a publisher and a date like any
        // other rate. The date is the FIRST of the month, because that is what the figure is about — the last
        // observation's date would name a day whose own rate is a different number.
        //
        // The source is the one the observations carry, never a literal: the average of a series is published
        // by whoever published the series, and naming anything else here would put a stranger on a document.
        return new ExchangeRateRecord([
            'from_currency' => $from,
            'to_currency' => $to,
            'basis' => $basis->value,
            'rate_scaled' => $average,
            'rate_date' => $on->startOfMonth(),
            'source' => $days->first()->source,
        ]);
    }

    /** The earliest published rate at or after the date asked for, within the forward bound. */
    private function nextPublished(string $from, string $to, CarbonImmutable $on, ExchangeRateBasis $basis): ?ExchangeRateRecord
    {
        return $this->pair($from, $to, $basis)
            ->whereDate('rate_date', '>=', $on->toDateString())
            ->whereDate('rate_date', '<=', $on->addDays(self::FORWARD_LIMIT_DAYS)->toDateString())
            ->orderBy('rate_date')
            ->first();
    }

    /** @return Builder<ExchangeRateRecord> */
    private function pair(string $from, string $to, ExchangeRateBasis $basis): Builder
    {
        return ExchangeRateRecord::query()
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->where('basis', $basis->value);
    }
}
