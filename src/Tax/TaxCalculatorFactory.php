<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\SuppliesTaxRates;
use Pushery\Billing\Contracts\TaxCalculator;
use Pushery\Billing\Exceptions\InvalidBillingConfig;
use Pushery\Billing\Preflight\CheckpointRegistry;

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

    /**
     * @param  ?CheckpointRegistry  $profiles  resolves the active jurisdiction profile, which may carry its
     *                                         country's rates; optional so a caller that only needs a
     *                                         calculator still constructs this with a config repository alone
     */
    public function __construct(
        private Repository $config,
        private ?CheckpointRegistry $profiles = null,
    ) {}

    public function make(): TaxCalculator
    {
        return match ($this->config->get('billing.tax', 'none')) {
            // The seller's own country drives the domestic-vs-cross-border reverse-charge decision.
            'eu_oss' => new EuOssTaxCalculator($this->sellerCountry(), $this->rateMatrix()),
            'provider', 'stripe' => new StripeTaxCalculator,
            default => new NoTaxCalculator,
        };
    }

    private function sellerCountry(): ?string
    {
        $country = $this->config->get('billing.company.country');

        return is_string($country) && $country !== '' ? $country : null;
    }

    /**
     * The configured country-and-category rate table, or null when none is configured.
     *
     * A malformed table is REFUSED rather than ignored. Ignoring it would leave the standard rate charged on
     * every reduced-rate supply — the same silent over- or under-charge the configuration exists to prevent,
     * and with no symptom, since a wrong rate looks exactly like a right one on an invoice. The absent case
     * is the only quiet one, because absent means "this jurisdiction has one band" rather than "something
     * went wrong here".
     */
    private function rateMatrix(): ?TaxRateMatrix
    {
        $matrix = $this->config->get('billing.tax_matrix');

        // Configuration wins where it is present. An operator who has priced their own table has a reason
        // the package cannot know — a rate the shipped profile has not caught up with, most obviously — and
        // a profile silently overriding that would be the package deciding it knows better about a number
        // whose correctness only the operator can be accountable for.
        if ($matrix === null) {
            return $this->profileRates();
        }

        if (! is_array($matrix) || ! is_array($matrix['rates'] ?? null) || ! is_string($matrix['valid_from'] ?? null)) {
            throw InvalidBillingConfig::forKey(
                'billing.tax_matrix',
                'an array with a "valid_from" date string and a "rates" array of country => '
                .'category => basis points, or null when the jurisdiction has a single rate band'
            );
        }

        /** @var array<string, array<string, int>> $rates */
        $rates = $matrix['rates'];

        return new TaxRateMatrix($rates, Carbon::parse($matrix['valid_from']));
    }

    /**
     * The active jurisdiction profile's own rates, where it carries any.
     *
     * This is what spares an operator in a shipped jurisdiction from hand-typing their own country's rates.
     * Hand-typed rates are wrong in a way nothing catches: a wrong rate looks exactly like a right one on an
     * invoice, and the mistake surfaces at the tax return rather than at the sale.
     */
    private function profileRates(): ?TaxRateMatrix
    {
        $profile = $this->profiles?->profile();

        if (! $profile instanceof SuppliesTaxRates) {
            return null;
        }

        return new TaxRateMatrix($profile->taxRates(), $profile->taxRatesValidFrom());
    }
}
