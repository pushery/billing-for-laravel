<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Pushery\Billing\Exceptions\ExchangeRateFeedUnreadable;

/**
 * Turns the central bank's CSV into observations — and is deliberately incapable of fetching one.
 *
 * The same split as the VAT-rate importer beside it, for the same reason: fetching lives with the caller, so
 * every case here is testable without a network. An importer whose own tests need the internet is one that
 * will eventually run without them.
 *
 * That sibling is named in prose rather than with a `@see`, and the omission is deliberate. The register
 * that finds classes nothing calls scans for a class NAME anywhere in the shipped tree — so a docblock
 * mentioning it would have counted as a caller, and quietly cleared a class that still has none.
 *
 * ## Why the SDMX endpoint and not the daily XML file
 *
 * Measured, 2026-07-27. Asked for Saturday to Monday, the SDMX service returns **only Monday** — the
 * weekend simply has no observation. The classic `eurofxref-daily.xml` fetched on a Saturday answers **HTTP
 * 200 carrying Friday's data**, with the real date inside the document.
 *
 * That difference decides which one is usable. The rule for a day the bank did not publish is the next
 * publication day, and implementing it correctly requires absence to BE absence. Against the daily file, a
 * missing day is indistinguishable from a repeated one, and a rate would be booked for a date the bank
 * never issued it for.
 *
 * ## Fail loud, never partially
 *
 * A row whose value or date cannot be read throws. It is tempting to skip it and import the rest — and that
 * is precisely how a series ends up with a hole nobody notices, answered later by the forward walk with a
 * neighboring day's rate. A refused import is visible; a quietly short one is not.
 */
final readonly class EcbRateParser
{
    /**
     * Read the published observations out of an SDMX `csvdata` response.
     *
     * @return list<PublishedRate> oldest first, in the direction the bank states: EUR to the quote currency
     *
     * @throws ExchangeRateFeedUnreadable when the shape is not what the service documents
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

        // `str_getcsv` yields null for an entirely empty field, so the cast is not decoration: mapping
        // `trim(...)` straight over the header would be a type error on a trailing comma, which a
        // hand-edited or re-exported file has every chance of carrying.
        $header = array_map(
            static fn (?string $column): string => trim((string) $column),
            str_getcsv(array_shift($lines), escape: ''),
        );

        $columns = array_flip($header);

        // Named lookups, never positional. The service is free to add columns, and a positional read would
        // then take a rate out of whichever field moved into that slot -- silently, and plausibly.
        foreach (['CURRENCY', 'TIME_PERIOD', 'OBS_VALUE'] as $required) {
            if (! array_key_exists($required, $columns)) {
                throw ExchangeRateFeedUnreadable::missingColumn($required, array_keys($columns));
            }
        }

        $observations = [];

        foreach ($lines as $line) {
            $row = str_getcsv($line, escape: '');

            $currency = trim($row[$columns['CURRENCY']] ?? '');
            $date = trim($row[$columns['TIME_PERIOD']] ?? '');
            $value = trim($row[$columns['OBS_VALUE']] ?? '');

            // A published series legitimately carries gaps as empty values -- a currency suspended, a day
            // withdrawn. That is not a malformed row and not a rate: it is skipped, and the forward walk
            // treats the day as unpublished, which is what it is.
            if ($value === '') {
                continue;
            }

            if ($currency === '' || ! $this->isDate($date) || ! is_numeric($value) || (float) $value <= 0) {
                throw ExchangeRateFeedUnreadable::unreadableRow($line);
            }

            $observations[] = new PublishedRate(
                'EUR',
                strtoupper($currency),
                FrozenExchangeRate::scale($value),
                CarbonImmutable::parse($date),
            );
        }

        usort($observations, static fn (PublishedRate $a, PublishedRate $b): int => $a->on <=> $b->on);

        return $observations;
    }

    /** Strict YYYY-MM-DD. A value that only half-parses is not a date the publisher stated. */
    private function isDate(string $value): bool
    {
        $parsed = date_create_immutable_from_format('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
