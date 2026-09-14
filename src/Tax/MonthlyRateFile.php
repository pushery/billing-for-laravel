<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Pushery\Billing\Exceptions\ExchangeRateFeedUnreadable;

/**
 * Reads announced monthly average rates out of a file an operator supplies.
 *
 * ## Why a file and not a fetch
 *
 * The rule this feeds takes an authority's ANNOUNCED monthly average — in Germany the ministry's table,
 * which is mandatory rather than preferred; a daily rate needs the tax office's permission. That table is
 * published behind a page that refuses automated retrieval, and going around a bot defense is not something
 * a published package should do: it turns the package into something that pretends to be a browser, it
 * breaks the next time the protection changes, and it is a promise to consumers that cannot be kept.
 *
 * So the operator downloads once a month what they download for their bookkeeping anyway, and points this at
 * it. The cost is a manual step. What it buys is that the figures on the documents are the figures the
 * authority published.
 *
 * ## Why the average is not computed from the daily series instead
 *
 * It is arithmetically tempting — the authority derives its table from the same reference rates the daily
 * importer already holds — and it is still the wrong number. The statute asks for the ANNOUNCED average, and
 * a self-computed one parts company with it the moment a day is missing from the local series, a publisher
 * revises an observation, or the rounding differs by a digit. None of that is visible: it produces a
 * plausible figure on a tax document that is checked, years later, against the official table.
 *
 * ## Fail loud, never partially
 *
 * A row that cannot be read refuses the whole file. A skipped row leaves a month with no average, and a month
 * with no average refuses every conversion in it — which somebody sees — while a quietly short import is
 * visible nowhere.
 */
final readonly class MonthlyRateFile
{
    /**
     * The columns a file carries, by name.
     *
     * Read by name rather than by position for the same reason the daily parser does it: a file that gains a
     * column — a country name, a note — must not move a rate into another field's meaning.
     *
     * @var list<string>
     */
    public const array COLUMNS = ['from', 'to', 'month', 'rate'];

    /**
     * Read the announced averages out of a delimited file.
     *
     * @return list<PublishedRate> one per row, dated to the first day of the month it is about
     *
     * @throws ExchangeRateFeedUnreadable when the shape is not the documented one
     */
    public function parse(string $csv): array
    {
        $lines = array_values(array_filter(
            array_map(trim(...), preg_split('/\R/', $csv) ?: []),
            static fn (string $line): bool => $line !== '',
        ));

        if ($lines === []) {
            throw ExchangeRateFeedUnreadable::empty();
        }

        // `str_getcsv` yields null for an entirely empty field, so the cast is not decoration: a trailing
        // comma in a hand-edited file would otherwise be a type error rather than a readable refusal.
        $header = array_map(
            static fn (?string $column): string => strtolower(trim((string) $column)),
            str_getcsv(array_shift($lines), escape: ''),
        );

        $columns = array_flip($header);

        foreach (self::COLUMNS as $required) {
            if (! array_key_exists($required, $columns)) {
                throw ExchangeRateFeedUnreadable::missingColumn($required, array_keys($columns));
            }
        }

        $rates = [];

        foreach ($lines as $line) {
            $row = str_getcsv($line, escape: '');

            $from = strtoupper(trim($row[$columns['from']] ?? ''));
            $to = strtoupper(trim($row[$columns['to']] ?? ''));
            $month = trim($row[$columns['month']] ?? '');
            $rate = trim($row[$columns['rate']] ?? '');

            // An empty value is a refusal here, unlike in the daily series. There, a gap is an ordinary
            // published absence — a suspended currency, a withdrawn day. A month with no announced average
            // is not published emptiness; it is a row somebody meant to fill.
            if (! $this->isCurrency($from) || ! $this->isCurrency($to) || $from === $to
                || ! $this->isMonth($month) || ! is_numeric($rate) || (float) $rate <= 0) {
                throw ExchangeRateFeedUnreadable::unreadableRow($line);
            }

            $rates[] = new PublishedRate(
                $from,
                $to,
                FrozenExchangeRate::scale($rate),
                CarbonImmutable::parse($month.'-01'),
            );
        }

        return $rates;
    }

    /** Strict YYYY-MM. A value that only half-parses names no month the authority announced. */
    private function isMonth(string $value): bool
    {
        $parsed = date_create_immutable_from_format('!Y-m', $value);

        return $parsed !== false && $parsed->format('Y-m') === $value;
    }

    private function isCurrency(string $value): bool
    {
        return preg_match('/^[A-Z]{3}$/', $value) === 1;
    }
}
