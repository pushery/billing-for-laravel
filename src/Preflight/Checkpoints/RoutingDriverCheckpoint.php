<?php

declare(strict_types=1);

namespace Pushery\Billing\Preflight\Checkpoints;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\GoLiveCheckpoint;
use Pushery\Billing\Contracts\RoutesMoney;
use Pushery\Billing\Enums\GoLiveStep;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\ValueObjects\CheckpointOutcome;

/**
 * The active driver can name a destination other than the platform.
 *
 * This is the same condition the boot guard refuses on, and it is here as well on purpose. The guard fires
 * when the switch is already on, which is one step too late to be useful: the operator wanted to know
 * BEFORE flipping it, and this is the line that tells them. Between them there is no gap where a
 * non-routing driver could carry a routed sale.
 *
 * Not waivable. A driver does not acquire the ability to route money because its key appears in a list;
 * waiving this would produce exactly the failure the whole marketplace layer exists to prevent — charges
 * that settle to the platform while the configuration says they were split.
 */
final readonly class RoutingDriverCheckpoint implements GoLiveCheckpoint
{
    public function __construct(
        private Repository $config,
        private BillingManager $drivers,
    ) {}

    public function key(): string
    {
        return 'configuration.driver_routes_money';
    }

    public function step(): GoLiveStep
    {
        return GoLiveStep::Configuration;
    }

    public function isBlocking(): bool
    {
        return true;
    }

    public function isWaivable(): bool
    {
        return false;
    }

    public function evaluate(): CheckpointOutcome
    {
        // Billing off means the no-op driver is active, and reporting on ITS shape would be meaningless:
        // the answer is not "this driver cannot route" but "there is no driver yet".
        if (! (bool) $this->config->get('billing.enabled', true)) {
            return CheckpointOutcome::fail(
                'Billing is disabled (billing.enabled = false), so the no-op driver is active and no money '.
                'can be routed to a merchant. Enable billing first.'
            );
        }

        $driver = $this->drivers->driver();

        if (! $driver instanceof RoutesMoney) {
            return CheckpointOutcome::fail(
                "The active billing driver [{$driver->name()}] does not route money to merchants. A driver ".
                'announces the capability by implementing Pushery\\Billing\\Contracts\\RoutesMoney.'
            );
        }

        return CheckpointOutcome::pass("The active billing driver [{$driver->name()}] routes money to merchants.");
    }
}
