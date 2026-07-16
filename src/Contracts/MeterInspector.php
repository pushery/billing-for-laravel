<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\MeterPriceFacts;

/**
 * Reads which usage meters actually exist at the provider — the seam `billing:meters:check` uses to catch
 * a metered tier whose provider meter was never created (or was archived). Nothing else notices that: the
 * boot guard only checks the DRIVER can meter, and reporting usage to a non-existent meter fails silently
 * on the far side, so the miss surfaces as a suspiciously small invoice a month later, if at all.
 */
interface MeterInspector
{
    /**
     * The event names of the provider's currently active meters. Empty when the driver has no remote-meter
     * concept (it bills usage locally) — which is safe, because a driver that cannot meter remotely never
     * carries a metered tier past the boot guard, so there is nothing to check against it.
     *
     * @return list<string>
     */
    public function activeMeterEventNames(): array;

    /**
     * What the provider's PRICE says about a metered component — the authority the local config must agree
     * with. Null when the price does not exist at the provider (or the driver cannot look one up).
     *
     * The allowance lives in the price, not in config: `included` there only drives the gauge. A price whose
     * free tier disagrees with it hands the customer a different number of free units than the interface
     * promised, and nothing anywhere throws.
     */
    public function priceFacts(string $providerPriceId): ?MeterPriceFacts;
}
