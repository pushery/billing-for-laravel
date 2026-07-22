<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Exceptions\TaxModeUnsupported;
use Pushery\Billing\Tax\TaxCalculatorFactory;

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

        // A mode nothing can resolve is refused HERE, at boot — not ignored. Ignoring it hands the decision
        // to the calculator factory, which finds no match and falls back to the no-op: 0% VAT on every
        // invoice, silently. Both shapes of unresolvable are covered together because they fail identically:
        // a NON-STRING (billing.tax becomes an array the moment someone adds a sub-key under it) and a
        // mistyped mode name ('eu_os'), which is the likelier of the two since it comes from an env var.
        if (! is_string($mode) || ! in_array($mode, TaxCalculatorFactory::MODES, true)) {
            throw TaxModeUnsupported::unresolvable($mode, TaxCalculatorFactory::MODES);
        }

        if ($mode === 'none') {
            return;
        }

        $supportsProviderTax = $this->drivers->capabilities()->supportsProviderTax;
        $driver = $this->drivers->driver()->name();

        // Every provider-delegating mode, not just the literal 'provider': 'stripe' is an alias that resolves
        // to the same provider calculator. Matching only 'provider' here classified 'stripe' as a LOCAL mode,
        // which refused to boot on exactly the driver it needs and passed on one that cannot apply it.
        if (in_array($mode, TaxCalculatorFactory::PROVIDER_MODES, true)) {
            if (! $supportsProviderTax) {
                throw TaxModeUnsupported::providerTaxUnsupported($driver, $mode);
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
