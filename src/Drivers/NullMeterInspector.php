<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers;

use Pushery\Billing\Contracts\MeterInspector;
use Pushery\Billing\ValueObjects\MeterPriceFacts;

/**
 * The default inspector for a driver with no remote-meter concept (the local billing engine bills usage
 * itself). It reports no active meters; `billing:meters:check` then has nothing to verify against, which is
 * correct — such a driver never carries a metered tier past the boot guard.
 */
final class NullMeterInspector implements MeterInspector
{
    /** @return list<string> */
    public function activeMeterEventNames(): array
    {
        return [];
    }

    /** No remote prices to inspect either — there is nothing for the check to compare the config against. */
    public function priceFacts(string $providerPriceId): ?MeterPriceFacts
    {
        return null;
    }
}
