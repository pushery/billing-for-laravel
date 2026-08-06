<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;

/**
 * Turns a source response into a proposal — and is deliberately incapable of doing anything else.
 *
 * There is no method here that writes a snapshot. That absence is the feature: a manipulated or misparsed
 * response cannot put a rate into production because the code that fetched it has no path to the file every
 * invoice is priced from. Adopting a proposal is a separate, human act, and the snapshot's header then
 * records who performed it.
 *
 * Pure, like the conformity probe next door: handed rows and a date, it decides. Fetching lives with the
 * caller, which is what lets every case be tested without a network — and an importer whose own tests need
 * the internet is one that will eventually run without them.
 */
final readonly class RateImporter
{
    /**
     * @param  list<array{memberState: string, type: string, rateType: string, value: float, comment?: string}>  $rows
     * @param  array<string, int>  $current  the rates in force today, in basis points
     */
    public function propose(
        array $rows,
        string $situationOn,
        CarbonImmutable $askedFrom,
        CarbonImmutable $askedTo,
        array $current,
    ): RateProposal {
        // Answered for a window nobody asked about. Not "no change" — no answer. The distinction is the
        // whole point: booking this as "nothing to do" is how a silent failure becomes a year of stale
        // rates while every log shows a successful run.
        if (! TedbRateReduction::answersFor($situationOn, $askedFrom, $askedTo)) {
            return new RateProposal(unreachable: true, situationOn: $situationOn);
        }

        $reduced = TedbRateReduction::reduce($rows);
        $proposed = $reduced['rates'];

        // A source that could not make up its mind about a country cannot be used to change that country,
        // and using the rest of a response that contradicts itself is optimism, not caution.
        if ($reduced['refused'] !== []) {
            return new RateProposal(
                proposed: $proposed,
                assessment: [
                    'implausible' => array_map(
                        static fn (string $state): string => "{$state} was given more than one standard rate",
                        array_keys($reduced['refused']),
                    ),
                    'vanished' => [],
                    'notable' => [],
                ],
                situationOn: $situationOn,
            );
        }

        $assessment = RatePlausibility::assess($current, $proposed);

        $changes = [];

        foreach ($proposed as $country => $bps) {
            $was = $current[$country] ?? null;

            if ($was !== $bps) {
                $changes[$country] = ['from' => $was ?? 0, 'to' => $bps];
            }
        }

        ksort($changes);

        return new RateProposal(
            proposed: $proposed,
            changes: $changes,
            assessment: $assessment,
            situationOn: $situationOn,
        );
    }

    /** The proposal for a source that could not be reached at all. */
    public function unreachable(): RateProposal
    {
        return new RateProposal(unreachable: true);
    }
}
