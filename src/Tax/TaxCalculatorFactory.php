<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\TaxCalculator;

/**
 * Selects the tax calculator from config('billing.tax'): the static EU-OSS VAT table, the
 * provider-delegate (Stripe Tax), or the no-op.
 *
 * An unresolvable mode still falls back to the no-op here, but that fallback is a last resort and NOT a
 * safety property: charging no tax is the dangerous direction for a seller, not the safe one, because it
 * under-declares silently. TaxSupportGuard refuses an unresolvable mode at boot so this fallback is never
 * reached in a booted application — see MODES, which is the authority both sides read.
 */
final readonly class TaxCalculatorFactory
{
    /**
     * Every tax mode make() can actually resolve to a calculator. This is the single authority for what a
     * valid billing.tax is: TaxSupportGuard refuses anything outside it at boot, and a lockstep test proves
     * each entry here really has a match arm below (so the two can never drift apart).
     *
     * @var list<string>
     */
    public const array MODES = ['none', 'eu_oss', 'provider', 'stripe'];

    /**
     * The subset of MODES that hands tax computation to the payment provider, and therefore REQUIRES a
     * driver that computes provider tax. 'stripe' is an alias of 'provider' here — it resolves to the same
     * calculator below, so any consumer of this classification must treat the two alike.
     *
     * @var list<string>
     */
    public const array PROVIDER_MODES = ['provider', 'stripe'];

    public function __construct(private Repository $config) {}

    public function make(): TaxCalculator
    {
        return match ($this->config->get('billing.tax', 'none')) {
            // The seller's own country drives the domestic-vs-cross-border reverse-charge decision.
            'eu_oss' => new EuOssTaxCalculator($this->sellerCountry()),
            'provider', 'stripe' => new StripeTaxCalculator,
            default => new NoTaxCalculator,
        };
    }

    private function sellerCountry(): ?string
    {
        $country = $this->config->get('billing.company.country');

        return is_string($country) && $country !== '' ? $country : null;
    }
}
