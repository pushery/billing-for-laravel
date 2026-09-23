<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;

/**
 * Turning a TEDB response into one standard rate per member state — and refusing where it cannot.
 *
 * ## The reduction rule, and why each clause is load-bearing
 *
 * TEDB returns **several rows per country**. The rule is: keep `type = STANDARD` **and**
 * `rate/type = DEFAULT`, group by member state, take the set of DISTINCT `rate/value`. Exactly one value is
 * the standard rate. More than one is a **refusal**, not a choice — two different standard rates for one
 * country at one moment means the response is not saying what the caller assumed, and picking either is
 * guessing with a plausible number.
 *
 * Three ways to get this wrong, all measured against the live service rather than reasoned about:
 *
 * - **Grouping naively puts Spain at 7.0%.** A Canary Islands row wins a `keyBy(memberState)` because it
 *   arrives last. The territory is outside the EU VAT area entirely, so the figure is not merely the wrong
 *   band — it is a different tax regime's number wearing Spain's country code. The service used to mark
 *   that row with its own rate type; today it reports it as `STANDARD`/`DEFAULT` like Spain's own, with the
 *   territory named in `comment`, so the row is recognized by the territory it names (see
 *   TERRITORIES_OUTSIDE_THE_VAT_AREA).
 * - **Discarding rows that carry a comment throws away six correct standard rates.** BE, CZ, FR, IE, LV and
 *   LU state their legal basis in `comment`. "Has a comment" looks like a tidy proxy for "is a footnote" and
 *   is nothing of the kind.
 * - **`EL` is Greece.** TEDB uses the EU's own code, not ISO's `GR`. `XI` (Northern Ireland) is never folded
 *   into the union list silently, and `GB` is simply absent — a lookup that quietly returns nothing for it
 *   would read as "no VAT there".
 */
final readonly class TedbRateReduction
{
    /**
     * The territories of member states that lie outside the EU VAT area (Article 6 of Directive
     * 2006/112/EC, and Article 349 TFEU for the French ones). A standard-rate row whose comment names one
     * is that territory's rate, not the member state's.
     *
     * Matched on the name rather than on "has a comment": six member states state their legal basis in the
     * comment of their ordinary standard row, and dropping commented rows would delete six correct rates.
     */
    private const array TERRITORIES_OUTSIDE_THE_VAT_AREA = [
        'Canary Islands', 'Ceuta', 'Melilla', 'Mount Athos', 'Åland', 'Aland', 'Heligoland', 'Helgoland',
        'Büsingen', 'Busingen', 'Livigno', 'Campione', 'Lugano', 'Guadeloupe', 'French Guiana', 'Guyane',
        'Martinique', 'Réunion', 'Reunion', 'Mayotte', 'Saint-Martin',
    ];

    /**
     * The standard rate per member state, in basis points.
     *
     * @param  list<array{memberState: string, type: string, rateType: string, value: float, comment?: string}>  $rows
     * @return array{rates: array<string, int>, refused: array<string, list<float>>}
     */
    public static function reduce(array $rows): array
    {
        /** @var array<string, list<float>> $seen */
        $seen = [];

        foreach ($rows as $row) {
            // Both filters, and neither is optional. `type` alone still admits reduced and parking bands;
            // `rateType` alone still admits a territory's own standard rate — which is how Spain becomes 7.
            if ($row['type'] !== 'STANDARD') {
                continue;
            }
            if ($row['rateType'] !== 'DEFAULT') {
                continue;
            }
            // NOT filtered on having a comment. Six member states state their legal basis there, and
            // dropping commented rows deletes six correct standard rates while looking like tidying. A
            // comment that NAMES a territory outside the VAT area is different: that row is the territory's.
            if (self::namesATerritoryOutsideTheVatArea($row['comment'] ?? '')) {
                continue;
            }
            $state = self::isoCodeFor($row['memberState']);

            if ($state === null) {
                continue;
            }

            if (! in_array($row['value'], $seen[$state] ?? [], true)) {
                $seen[$state][] = $row['value'];
            }
        }

        $rates = [];
        $refused = [];

        foreach ($seen as $state => $values) {
            if (count($values) === 1) {
                $rates[$state] = (int) round($values[0] * 100);

                continue;
            }

            // Two distinct standard rates for one state at one moment. Refused rather than resolved: any
            // resolution is a guess, and a guess here produces a number that looks exactly like a fact.
            $refused[$state] = $values;
        }

        ksort($rates);
        ksort($refused);

        return ['rates' => $rates, 'refused' => $refused];
    }

    /**
     * The ISO code TEDB's member-state code corresponds to, or null where it must not be folded in.
     *
     * `XI` is Northern Ireland under the Windsor Framework. It is deliberately NOT mapped: it participates
     * in the union's rules for goods only, and quietly listing it as a member state would put it into every
     * place-of-supply answer as though it were one.
     */
    private static function isoCodeFor(string $memberState): ?string
    {
        $code = strtoupper(trim($memberState));

        return match ($code) {
            'EL' => 'GR',
            'XI' => null,
            default => $code === '' ? null : $code,
        };
    }

    /** Whether a row's comment names a territory that lies outside the EU VAT area. */
    private static function namesATerritoryOutsideTheVatArea(string $comment): bool
    {
        return array_any(self::TERRITORIES_OUTSIDE_THE_VAT_AREA, fn (string $territory): bool => mb_stripos($comment, $territory) !== false);
    }

    /**
     * Whether a response can hold for the window that was asked about.
     *
     * `$situationOn` is the day the NEWEST rate situation in the response began (the service states it on
     * every row). The response holds for the window when that situation was already in force on its first
     * day, and when the window has already begun.
     *
     * **Both halves are traps that were measured, one per direction.** A day beyond the service's data is
     * answered SILENTLY WITH CURRENT DATA: `from=2027-01-01` came back HTTP 200, no fault, no warning, and
     * every row's situation began before 2027. So a window that has not begun cannot be confirmed, and the
     * check says so instead of answering "what rate applies in 2027" with today's rate. In the other
     * direction, a response whose newest situation began after the window's first day describes a change
     * that the window does not cover from its start, so it is not one answer for the whole window.
     *
     * The parsing has its own trap, and it bites in both directions. `situationOn` arrives as
     * `2026-07-01+02:00`, a date carrying a timezone offset. `createFromFormat('Y-m-d', …)` reads that
     * wrong. But so does parsing it as an INSTANT: `+02:00` makes midnight on the 1st land at 22:00 on the
     * previous day in UTC, so the situation silently begins one calendar day earlier. That day is a quarter
     * boundary, which is exactly where rate changes happen.
     *
     * `situationOn` is a calendar date the service states, not a moment in time. So the date part is taken
     * as written and the offset is discarded, deliberately.
     */
    public static function answersFor(string $situationOn, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($situationOn), $matches) !== 1) {
            // An unparseable answer is not an answer. Treated as "did not respond for this window" rather
            // than optimistically accepted — a probe that shrugs at a malformed date reports agreement it
            // never established.
            return false;
        }

        if ($to->startOfDay()->greaterThan(CarbonImmutable::today())) {
            return false;
        }

        return CarbonImmutable::parse($matches[1])->startOfDay()->lessThanOrEqualTo($from->startOfDay());
    }
}
