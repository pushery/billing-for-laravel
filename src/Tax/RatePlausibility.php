<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

/**
 * Whether a proposed set of rates is plausible enough to be worth a human's attention.
 *
 * ## The bounds come from the law, not from taste
 *
 * A member state's standard rate may not go below **15%** (Art. 97 of the VAT Directive) and a reduced band
 * not below **5%** (Art. 99). Those are not sanity checks somebody invented — they are the floor the
 * directive sets, so a proposal underneath one is not an aggressive rate, it is a parsing error wearing a
 * number. Picking limits by feel would have produced something like "between 5 and 30", which admits 91% as
 * readily as 19%.
 *
 * ## What is checked, and why each one exists
 *
 * - **A rate below its floor** — the response was misread. Nothing legitimate lives there.
 * - **A country that disappeared** — the single most dangerous shape. A shorter list looks structurally
 *   valid, so a silent accept deletes a country's rate and the next invoice to it is priced at whatever the
 *   fallback happens to be. Never a silent deletion.
 * - **A jump of more than five points** — real, but rare enough that it should be looked at. Not refused:
 *   a state genuinely can move five points, and a check that refuses the truth teaches people to bypass it.
 *   Flagged, so a human decides.
 */
final readonly class RatePlausibility
{
    /** The floor a standard rate may not go below, in basis points (Art. 97). */
    public const int STANDARD_FLOOR_BPS = 1_500;

    /** The floor a reduced band may not go below, in basis points (Art. 99). */
    public const int REDUCED_FLOOR_BPS = 500;

    /**
     * The highest a standard rate can plausibly be, in basis points.
     *
     * The directive sets a floor and no ceiling, so this one is argued rather than cited — but it is argued
     * the same way: the highest standard rate in the union is Hungary's 27%, and nothing is near 30%. A
     * proposal above it is not an aggressive rate, it is a misread response, exactly as one below the floor
     * is. Without this bound a response carrying 91% clears both the floor and the arithmetic ceiling of
     * 100% and arrives on a human's desk looking merely unusual.
     */
    public const int STANDARD_CEILING_BPS = 3_000;

    /** A move larger than this is real but worth a look, in basis points. */
    public const int NOTABLE_JUMP_BPS = 500;

    /**
     * @param  array<string, int>  $current  what is shipped today
     * @param  array<string, int>  $proposed  what a source is offering
     * @return array{implausible: list<string>, vanished: list<string>, notable: list<string>}
     */
    public static function assess(array $current, array $proposed): array
    {
        $implausible = [];
        $notable = [];

        foreach ($proposed as $country => $bps) {
            if ($bps < self::STANDARD_FLOOR_BPS) {
                // Below the directive's floor. Not an aggressive rate — a misread response.
                $implausible[] = "{$country} at {$bps} basis points is below the ".self::STANDARD_FLOOR_BPS.' floor';

                continue;
            }

            if ($bps > self::STANDARD_CEILING_BPS) {
                $implausible[] = "{$country} at {$bps} basis points is above the "
                    .self::STANDARD_CEILING_BPS.' ceiling, which no member state is near';

                continue;
            }

            $was = $current[$country] ?? null;

            if ($was !== null && abs($bps - $was) > self::NOTABLE_JUMP_BPS) {
                // Flagged, never refused. A state genuinely can move five points, and a check that refuses
                // the truth is one people learn to bypass — which costs more than the check ever saved.
                $notable[] = "{$country} moves from {$was} to {$bps} basis points";
            }
        }

        // A country we price that the proposal does not mention. The most dangerous shape here, because a
        // shorter list is structurally valid and a silent accept simply deletes the country.
        $vanished = array_values(array_diff(array_keys($current), array_keys($proposed)));

        return ['implausible' => $implausible, 'vanished' => $vanished, 'notable' => $notable];
    }

    /**
     * Whether a proposal may be written down at all.
     *
     * A notable jump does NOT block: it is information for the person reviewing, not grounds for refusal.
     *
     * @param  array{implausible: list<string>, vanished: list<string>, notable: list<string>}  $assessment
     */
    public static function usable(array $assessment): bool
    {
        return $assessment['implausible'] === [] && $assessment['vanished'] === [];
    }
}
