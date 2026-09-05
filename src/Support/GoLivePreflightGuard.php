<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Exceptions\MarketplaceNotReadyForGoLive;
use Pushery\Billing\Preflight\MarketplacePreflight;

/**
 * Ties the marketplace switch to the checklist instead of to the operator's memory.
 *
 * A go-live checklist that is only a command is a checklist somebody can skip, and skipping it leaves no
 * trace: the marketplace comes up, sells, and books everything under a configuration nobody signed off.
 * Binding the switch to the result means the shortcut does not exist — `marketplace.enabled = true` with an
 * open blocking point is a refusal to boot, naming the points.
 *
 * It re-runs the checklist rather than trusting a stored receipt. Every point IT RUNS is a cheap read of
 * local state, so running them is affordable -- the database-reading ones are excluded here and belong to
 * the console command; a receipt, by contrast, would certify the moment it was written and
 * would keep certifying it after the configuration underneath had changed.
 *
 * A no-op for the single-seller default: with billing.marketplace.enabled off the guard returns before it
 * reads any other key, resolves any checkpoint, or touches the registry.
 */
final readonly class GoLivePreflightGuard
{
    public function __construct(
        private Repository $config,
        private MarketplacePreflight $preflight,
    ) {}

    public function verify(): void
    {
        if (! (bool) $this->config->get('billing.marketplace.enabled', false)) {
            return;
        }

        // The BOOT set: configuration and bindings only. GoLiveCheckpoint promises the checklist reads no
        // database, and one shipped checkpoint has to break that promise to do its job -- so it is excluded
        // here and runs from the console instead. Without this, a non-blocking checkpoint could refuse the
        // whole application: an exception out of evaluate() does not care what isBlocking() returned, and on
        // an unmigrated schema the query throws.
        $report = $this->preflight->run(includeStoredState: false);

        if ($report->passed()) {
            return;
        }

        throw MarketplaceNotReadyForGoLive::withOpenBlockers($report->openBlockers());
    }
}
