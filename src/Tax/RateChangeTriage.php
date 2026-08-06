<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Enums\RateChangeClass;

/**
 * Sorts proposed rate changes into how much attention each one actually deserves.
 *
 * ## The asymmetry that decides the default
 *
 * A confirmed **increase** applies by default. That looks like the risky direction and is the safe one:
 * failing to apply a real increase means undercharging, which the platform pays and an audit discovers a
 * year later — the exact shape of the incident behind this milestone. Overcharging is noticed by the buyer
 * the same day and can be corrected. Between a mistake that surfaces immediately and one that surfaces at
 * an audit, the automation should be biased toward the first.
 *
 * A **decrease** holds, for the mirror reason: a decrease that is not real undercharges too.
 *
 * ## What "never invoiced there" is doing in a safety mechanism
 *
 * It is what keeps the other two classes readable. A change to a country this installation has never billed
 * is not a smaller version of a real change — it is of no consequence to this operator at all, and spending
 * a prompt on it is precisely how people learn to click without reading. The class exists so the prompts
 * that remain are worth stopping for.
 */
final readonly class RateChangeTriage
{
    /**
     * @param  array<string, array{from: int, to: int}>  $changes
     * @param  list<string>  $invoicedCountries  countries this installation has actually billed into
     * @param  array{implausible: list<string>, vanished: list<string>, notable: list<string>}  $assessment
     * @return array<string, RateChangeClass>
     */
    public static function classify(array $changes, array $invoicedCountries, array $assessment): array
    {
        $everInvoiced = array_map(strtoupper(...), $invoicedCountries);
        $notable = self::countriesNamedIn($assessment['notable']);
        $vanished = array_map(strtoupper(...), $assessment['vanished']);

        $classified = [];

        foreach ($changes as $country => $change) {
            $code = strtoupper((string) $country);

            if (! in_array($code, $everInvoiced, true)) {
                $classified[$country] = RateChangeClass::RecordedOnly;

                continue;
            }

            // A country appearing for the first time is a structural change, not a rate move, and gets the
            // same treatment as one disappearing: a person looks at it.
            $appearing = $change['from'] === 0;

            $classified[$country] = match (true) {
                $appearing,
                in_array($code, $vanished, true),
                in_array($code, $notable, true),
                $change['to'] < $change['from'] => RateChangeClass::HeldForApproval,
                default => RateChangeClass::ScheduledIncrease,
            };
        }

        return $classified;
    }

    /**
     * The country codes named in a list of assessment notes.
     *
     * The notes are written for people, so they are read back by pattern rather than restructured — the
     * alternative is a parallel machine-readable list that drifts from the prose nobody updates twice.
     *
     * @param  list<string>  $notes
     * @return list<string>
     */
    private static function countriesNamedIn(array $notes): array
    {
        $codes = [];

        foreach ($notes as $note) {
            if (preg_match('/^([A-Z]{2})\b/', $note, $matches) === 1) {
                $codes[] = $matches[1];
            }
        }

        return $codes;
    }
}
