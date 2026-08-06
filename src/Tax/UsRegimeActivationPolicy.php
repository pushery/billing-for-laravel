<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\CollectsSellerTaxDeclarations;
use Pushery\Billing\Enums\UsRegimeActivationTrigger;

/**
 * When the regime has to be switched on — as conditions that can be evaluated, not a paragraph to re-read.
 *
 * ## It evaluates readings; it does not count anything
 *
 * The platform-wide counter is a separate piece of work, and building a second one here would be worse than
 * having none: two counters over the same money disagree eventually, and the one that disagrees quietly is
 * the one an alarm is wired to. So this takes readings as arguments. What it owns is the judgement — which
 * limits those readings are approaching, and how close is close enough to act.
 *
 * ## Close enough is a share, and the share is configurable
 *
 * Waiting for a limit to be crossed is waiting too long: registration takes weeks, the obligation starts at
 * the crossing, and the gap is a period of selling into a region unregistered. Hence a fraction of the limit
 * rather than the limit. It is configuration and not a constant here because the right lead time depends on
 * how fast an operator can actually register, which the package cannot know.
 *
 * ## Either figure fires
 *
 * A region that publishes both a money limit and a transaction count means either. Many small sales cross
 * the count while staying under the money; one large sale does the reverse. Requiring both would produce a
 * monitor that stays quiet through exactly the cases that catch platforms out.
 */
final readonly class UsRegimeActivationPolicy
{
    public function __construct(private Repository $config) {}

    /**
     * Which of the named conditions currently hold.
     *
     * @param  array<string, array{net_minor: int, transactions: int}>  $platformReadings  region → the platform's own totals
     * @return list<UsRegimeActivationTrigger>
     */
    public function triggers(
        CollectsSellerTaxDeclarations $profile,
        array $platformReadings,
        bool $establishment = false,
        bool $marketDecision = false,
    ): array {
        $triggers = [];

        if ($establishment) {
            $triggers[] = UsRegimeActivationTrigger::DomesticEstablishment;
        }

        if ($this->approachingLimit($profile, $platformReadings) !== []) {
            $triggers[] = UsRegimeActivationTrigger::ApproachingRegionalLimit;
        }

        if ($marketDecision) {
            $triggers[] = UsRegimeActivationTrigger::MarketDecision;
        }

        return $triggers;
    }

    /**
     * The regions whose limits the platform is closing on, so an alarm can name them rather than say "one".
     *
     * @param  array<string, array{net_minor: int, transactions: int}>  $platformReadings
     * @return list<string>
     */
    public function approachingLimit(CollectsSellerTaxDeclarations $profile, array $platformReadings): array
    {
        $share = $this->shareBps();
        $regions = [];

        foreach ($profile->regionalLimits() as $region => $limit) {
            $reading = $platformReadings[$region] ?? ['net_minor' => 0, 'transactions' => 0];

            $money = $limit['net_minor'] > 0 && $this->reaches($reading['net_minor'], $limit['net_minor'], $share);
            $count = $limit['transactions'] > 0 && $this->reaches($reading['transactions'], $limit['transactions'], $share);

            if ($money || $count) {
                $regions[] = $region;
            }
        }

        return $regions;
    }

    /** Whether the regime is switched on right now. Any consequence has to ask this first. */
    public function active(): bool
    {
        return (bool) $this->config->get('billing.tax_us.enabled', false);
    }

    /**
     * Whether the switch and the conditions agree — the answer worth reporting.
     *
     * A triggered condition with the regime still off is the state somebody has to act on; the reverse (on,
     * nothing triggered) is deliberate and fine, because a decision to sell into the market needs no counter.
     *
     * @param  array<string, array{net_minor: int, transactions: int}>  $platformReadings
     */
    public function overdue(
        CollectsSellerTaxDeclarations $profile,
        array $platformReadings,
        bool $establishment = false,
        bool $marketDecision = false,
    ): bool {
        return ! $this->active()
            && $this->triggers($profile, $platformReadings, $establishment, $marketDecision) !== [];
    }

    /** How old the limits being judged against are, so a stale profile is visible rather than assumed fresh. */
    public function limitsValidFrom(CollectsSellerTaxDeclarations $profile): CarbonInterface
    {
        return $profile->regionalLimitsValidFrom();
    }

    /** The share of a limit that counts as approaching it. */
    public function shareBps(): int
    {
        $configured = $this->config->get('billing.tax_us.activation_share_bps');

        return is_int($configured) && $configured > 0 ? $configured : 5_000;
    }

    /** Whether a reading has reached the configured share of a limit. Integer arithmetic: no float drift. */
    private function reaches(int $reading, int $limit, int $shareBps): bool
    {
        return $reading * 10_000 >= $limit * $shareBps;
    }
}
