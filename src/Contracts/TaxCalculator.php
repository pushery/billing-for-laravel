<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\TaxContext;

/**
 * Pluggable tax: a Stripe-Tax pass-through, a static EU-OSS VAT table, or a no-op. Tax is a driver
 * capability the package fills locally when the provider lacks it — the neutral layer never assumes
 * provider-computed tax.
 */
interface TaxCalculator
{
    /** The tax due on a net amount for a tax context (zero when no tax applies). */
    public function calculate(Money $net, TaxContext $context): Money;
}
