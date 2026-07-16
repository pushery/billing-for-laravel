<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\TaxCalculator;

/**
 * Selects the tax calculator from config('billing.tax'): the static EU-OSS VAT table, the
 * provider-delegate (Stripe Tax), or the no-op. An unknown or missing mode falls back to no tax, so a
 * misconfiguration never silently invents tax on a customer's invoice.
 */
final readonly class TaxCalculatorFactory
{
    public function __construct(private Repository $config) {}

    public function make(): TaxCalculator
    {
        return match ($this->config->get('billing.tax', 'none')) {
            'eu_oss' => new EuOssTaxCalculator,
            'provider', 'stripe' => new StripeTaxCalculator,
            default => new NoTaxCalculator,
        };
    }
}
