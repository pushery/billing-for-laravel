<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Exceptions\TaxModeUnsupported;

/**
 * Refuses to boot when the configured tax mode cannot reach what the customer is charged.
 *
 * Tax is a driver capability, not a checkout option: "provider" defers to a provider that computes tax
 * (Stripe Tax), a local mode ("eu_oss") computes VAT from a table, "none" adds nothing. The danger the
 * guard removes is silent under-collection — a local mode configured on a driver that hands the charge to
 * the provider (Stripe) would compute VAT the provider never charges, so the invoice is short and nothing
 * errors until the return is filed. A local tax figure only reaches an invoice on a driver that produces
 * the invoice itself (a local-engine driver); a provider-tax driver must use "provider" or "none".
 *
 * Costs nothing for the default: "none" returns immediately.
 */
final readonly class TaxSupportGuard
{
    public function __construct(
        private Repository $config,
        private BillingManager $drivers,
    ) {}

    public function verify(): void
    {
        $mode = $this->config->get('billing.tax', 'none');

        if (! is_string($mode) || $mode === 'none') {
            return;
        }

        $supportsProviderTax = $this->drivers->capabilities()->supportsProviderTax;
        $driver = $this->drivers->driver()->name();

        if ($mode === 'provider') {
            if (! $supportsProviderTax) {
                throw TaxModeUnsupported::providerTaxUnsupported($driver);
            }

            return;
        }

        // Any other configured mode is a LOCAL computation (eu_oss today). It can only be applied by a
        // driver that produces the invoice itself; a provider-tax driver defers the charge to the provider
        // and would leave the locally-computed VAT uncollected.
        if ($supportsProviderTax) {
            throw TaxModeUnsupported::localTaxUnapplicable($driver, $mode);
        }
    }
}
