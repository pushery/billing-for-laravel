<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\Entitlements;
use Pushery\Billing\Entitlements\ConfigEntitlementsFactory;
use Pushery\Billing\Enums\SwapTiming;

/**
 * Decides WHEN a plan swap takes effect, from the direction of the swap and one configured default.
 *
 * The direction is not a matter of price — it is the tier's position in the configured ranking, so a
 * cheaper tier that sits higher in the list is still an upgrade. That ranking already drives
 * Entitlements::isUpgradeOver; this reads the same source so the screen and the swap can never disagree
 * about which way a change goes.
 *
 * An upgrade is always immediate. A downgrade waits until the period end by default, because the current
 * cycle is already paid at the higher tier — but that default is a product decision, so it is read from
 * config('billing.subscriptions.downgrade_timing') and can be set to immediate. A move to the same tier is
 * neither, and callers must treat it as a no-op rather than scheduling an empty change.
 */
final readonly class PlanSwapPlanner
{
    public function __construct(
        private ConfigEntitlementsFactory $entitlements,
        private Repository $config,
    ) {}

    /**
     * When a swap from the current tier to the target tier should take effect, or null when they are the
     * same tier — a no-op the caller must not schedule.
     */
    public function timingFor(string $currentTierKey, string $targetTierKey): ?SwapTiming
    {
        if ($currentTierKey === $targetTierKey) {
            return null;
        }

        return $this->direction($currentTierKey, $targetTierKey) === Direction::Upgrade
            ? SwapTiming::Immediate
            : $this->downgradeDefault();
    }

    /** Whether moving to the target tier is an upgrade relative to the current one. */
    public function isUpgrade(string $currentTierKey, string $targetTierKey): bool
    {
        return $this->direction($currentTierKey, $targetTierKey) === Direction::Upgrade;
    }

    private function direction(string $currentTierKey, string $targetTierKey): Direction
    {
        $current = $this->entitlementsFor($currentTierKey);
        $target = $this->entitlementsFor($targetTierKey);

        // isUpgradeOver is strict (>): equal ranks are not an upgrade. Two DIFFERENT keys can only share a
        // rank if one is unranked (-1), in which case moving toward the ranked one is the upgrade and away
        // from it the downgrade — the strict comparison lands both correctly.
        return $target->isUpgradeOver($current) ? Direction::Upgrade : Direction::Downgrade;
    }

    private function entitlementsFor(string $tierKey): Entitlements
    {
        return $this->entitlements->for($tierKey);
    }

    private function downgradeDefault(): SwapTiming
    {
        $configured = $this->config->get('billing.subscriptions.downgrade_timing');

        // An unreadable or unknown value falls back to the safe default rather than throwing: a swap must
        // not become unavailable because a config string was mistyped, and period-end is the direction that
        // never owes the customer a refund.
        return (is_string($configured) ? SwapTiming::tryFrom($configured) : null) ?? SwapTiming::PeriodEnd;
    }
}

/** @internal the two directions a swap can go; kept private to the planner. */
enum Direction
{
    case Upgrade;
    case Downgrade;
}
