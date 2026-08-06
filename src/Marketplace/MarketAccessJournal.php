<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\MarketAccess;
use Pushery\Billing\Models\MarketAccessEntry;

/**
 * Since when each market has been open, which configuration cannot answer.
 *
 * ## Why a log and not just the setting
 *
 * The setting says which countries are open today. A return covers a period, and whether a country belonged
 * in that period depends on when it was opened — a question the file cannot answer at all, because it has no
 * history. Worse, it answers confidently: read months later it looks like it was always this way.
 *
 * ## Only changes are recorded
 *
 * Writing the whole list on every run would bury the four entries that matter under thousands that say
 * nothing happened. So an entry appears when a country's standing actually differs from the last one
 * recorded for it — which also makes the log readable as what it is: a list of decisions.
 */
final readonly class MarketAccessJournal
{
    public function __construct(private MarketAllowlist $markets) {}

    /**
     * Record any market whose standing has changed since it was last recorded.
     *
     * @return list<array{country: string, from: ?MarketAccess, to: MarketAccess}> the changes written
     */
    public function sync(?string $actor = null, ?CarbonInterface $at = null): array
    {
        $moment = $at ?? Carbon::now();
        $changes = [];

        foreach ($this->declared() as $country => $state) {
            $previous = $this->currentState($country);

            if ($previous === $state) {
                continue;
            }

            MarketAccessEntry::query()->create([
                'country' => $country,
                'state' => $state,
                'actor' => $actor,
                'recorded_at' => $moment,
            ]);

            $changes[] = ['country' => $country, 'from' => $previous, 'to' => $state];
        }

        return $changes;
    }

    /** What the log says about a market today, or null where it has never been recorded. */
    public function currentState(string $country): ?MarketAccess
    {
        $entry = MarketAccessEntry::query()
            ->where('country', strtoupper($country))
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        return $entry instanceof MarketAccessEntry ? $entry->state : null;
    }

    /** When a market last became open, or null where it never has. */
    public function openSince(string $country): ?CarbonInterface
    {
        $entry = MarketAccessEntry::query()
            ->where('country', strtoupper($country))
            ->where('state', MarketAccess::Open)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        return $entry instanceof MarketAccessEntry ? $entry->recorded_at : null;
    }

    /**
     * Markets whose configured standing has never been recorded, or has drifted from the log.
     *
     * The gap this reports is a real one: somebody edited the file and nobody wrote down that they did, so
     * the trail a return would be justified by has a hole exactly where a decision was made.
     *
     * @return list<string>
     */
    public function unrecorded(): array
    {
        $pending = [];

        foreach ($this->declared() as $country => $state) {
            if ($this->currentState($country) !== $state) {
                $pending[] = $country;
            }
        }

        return $pending;
    }

    /**
     * The configured standing of every named market.
     *
     * @return array<string, MarketAccess>
     */
    private function declared(): array
    {
        $states = [];

        foreach ($this->markets->declaredMarkets() as $country) {
            $states[strtoupper($country)] = $this->markets->stateOf($country);
        }

        return $states;
    }
}
