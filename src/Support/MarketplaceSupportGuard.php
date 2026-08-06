<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\RoutesMoney;
use Pushery\Billing\Exceptions\MarketplaceUnsupported;

/**
 * Refuses to boot when the marketplace is switched on over a driver that cannot route money.
 *
 * The state this exists to remove is a marketplace that reads as enabled and behaves as single-seller.
 * Nothing about it looks wrong: the config says the marketplace is on, screens render, charges
 * succeed — and every one of them settles to the platform, because the driver has no way to name a
 * destination. The money has already moved by the time anyone notices, and moving it afterwards is a
 * manual, per-transaction correction.
 *
 * A no-op for the single-seller default: with billing.marketplace.enabled off (the shipped value) the
 * guard returns before it resolves anything, so a single-seller install pays no boot cost and reads no
 * marketplace config at all.
 */
final readonly class MarketplaceSupportGuard
{
    public function __construct(
        private Repository $config,
        private BillingManager $drivers,
    ) {}

    public function verify(): void
    {
        if (! (bool) $this->config->get('billing.marketplace.enabled', false)) {
            return;
        }

        $driver = $this->drivers->driver();

        if (! $driver instanceof RoutesMoney) {
            throw MarketplaceUnsupported::driverCannotRoute($driver->name());
        }
    }
}
