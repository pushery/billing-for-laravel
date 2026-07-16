<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Contracts\TaxCalculator;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\TaxContext;

/**
 * The provider-delegate tax calculator: tax is computed by the provider (Stripe Tax) on its own
 * invoice, so locally it surfaces zero rather than double-taxing. Distinct from NoTaxCalculator by
 * intent — "the provider adds tax" versus "there is no tax" — and it is the named seam a full
 * Stripe-Tax preview integration rebinds when it wants to show the provider's figure before checkout.
 */
final class StripeTaxCalculator implements TaxCalculator
{
    public function calculate(Money $net, TaxContext $context): Money
    {
        return Money::zero($net->currency);
    }
}
