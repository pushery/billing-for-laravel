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
 * answers. Nothing is resolved forward and nothing is computed: a month either has an announced average
 * or it does not, the next month's average is not a late answer for this one, and the mean of the daily
 * series is a figure nobody announced.
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
            ? $this->announcedMonth($from, $to, $on, $basis)
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

    /**
     * The average ANNOUNCED for the month the date falls in, stored against the month's first day.
     *
     * Read as a row, never computed from the daily series, and the difference is the point. Taking the
     * arithmetic mean of the month's published days produces the aggregation the ministry's table is made
     * OF, which is not the same thing as the table: the two part company the moment a day is missing from
     * the local series, a publisher revises an observation, or the rounding differs by a digit. Every one of
     * those is invisible, and what it produces is a plausible figure nobody published, on a tax document
     * that is checked years later against the official one.
     *
     * A month with no announced average is `null`, which becomes a refusal. That is the answer the statute
     * leaves: the announced average is mandatory, and a daily rate in its place needs the tax office's
     * permission — neither of which a package may grant itself. `billing:exchange-rates:import-file` is how
     * the figures get here, because the table is published behind a page that refuses automated retrieval.
     */
    private function announcedMonth(string $from, string $to, CarbonImmutable $on, ExchangeRateBasis $basis): ?ExchangeRateRecord
    {
        return $this->pair($from, $to, $basis)
            ->whereDate('rate_date', $on->startOfMonth()->toDateString())
            ->first();
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
