<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Contracts\TaxCalculator;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\TaxContext;

/**
 * The no-op tax calculator: never adds tax. The default for projects that handle tax elsewhere (or
 * not at all), so the neutral layer never assumes tax without one being configured.
 */
final class NoTaxCalculator implements TaxCalculator
{
    public function calculate(Money $net, TaxContext $context): Money
    {
        return Money::zero($net->currency);
    }
}
