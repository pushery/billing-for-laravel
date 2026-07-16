<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Pushery\Billing\Catalogs\MeterCatalog;
use Pushery\Billing\Exceptions\MeteringUnsupported;

/**
 * Refuses to run a tier that bills for usage on a driver that cannot report usage.
 *
 * There is no graceful degradation available here. The app would count every unit its customers use,
 * report none of it, and invoice them for the base fee alone — and nothing would look broken until the
 * month's revenue came in short. Failing at boot is the kindest outcome on offer.
 *
 * Costs nothing for an app that meters nothing: it reads config and stops before touching a driver.
 */
final readonly class MeteringSupportGuard
{
    public function __construct(
        private MeterCatalog $meters,
        private BillingManager $drivers,
    ) {}

    public function verify(): void
    {
        $meter = $this->meters->firstBillableMeter();

        if ($meter === null) {
            return;
        }

        if (! $this->drivers->capabilities()->supportsMeteredNative) {
            throw MeteringUnsupported::forDriver($this->drivers->driver()->name(), $meter);
        }
    }
}
