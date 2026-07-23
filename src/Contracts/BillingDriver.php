<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\DriverCapabilities;

/**
 * A payment driver: the composition root that ties a provider's two layers together and declares
 * what it can do natively. The BillingManager resolves the active driver by name; the package/UI
 * queries {@see capabilities()} and fills any gaps with its own local engine.
 */
interface BillingDriver
{
    /** The driver's stable key, e.g. "stripe". */
    public function name(): string;

    public function rails(): PaymentRails;

    public function engine(): BillingEngine;

    public function capabilities(): DriverCapabilities;
}
