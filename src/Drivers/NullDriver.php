<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers;

use Pushery\Billing\Contracts\BillingDriver;
use Pushery\Billing\Contracts\BillingEngine;
use Pushery\Billing\Contracts\PaymentRails;
use Pushery\Billing\ValueObjects\DriverCapabilities;

/**
 * The no-op driver returned when the master switch (billing.enabled) is off. It resolves cleanly and
 * reports no capabilities, so a clone without billing boots and schedules without errors; only actual
 * money operations fail loudly (see NullPaymentRails).
 */
final class NullDriver implements BillingDriver
{
    public function name(): string
    {
        return 'null';
    }

    public function rails(): PaymentRails
    {
        return new NullPaymentRails;
    }

    public function engine(): BillingEngine
    {
        return new NullBillingEngine;
    }

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities;
    }
}
