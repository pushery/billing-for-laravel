<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Exceptions\FanPriceTooLow;
use Pushery\Billing\Exceptions\InvalidBillingConfig;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PlatformFee;
use Pushery\Billing\ValueObjects\PricedSale;

/**
 * A tip or a pay-what-you-want price: a fee the FAN chooses, run through the ordinary sale pipeline.
 *
 * A tip is not a donation and takes no side path. It is consideration for the creator's supply — the fan
 * sought the channel out — so it carries the same regime, the same commission and the same document chain
 * as any sale, and the tax and place follow the referenced product rather than the tip.
 *
 * Two things this has to hold that a catalog sale gets for free. A catalog price cannot be chosen by the
 * buyer, which is the package's defense against price injection; a fan-chosen amount reintroduces that
 * exposure, so the floor is enforced HERE, on the server, from config — never trusted from the request. And
 * a chosen amount of zero is not a sale of nothing: it is no sale, with no transaction, no document and no
 * reportable inflow, and that has to be an explicit refusal rather than something that merely falls out of
 * the arithmetic.
 */
final readonly class FanChosenPricing
{
    public function __construct(
        private Repository $config,
        private RoutedPricing $pricing,
    ) {}

    /** Whether tipping is switched on. */
    public function tipsEnabled(): bool
    {
        return (bool) $this->config->get('billing.marketplace.tips.enabled', false);
    }

    /**
     * Price a tip, or return null when there is nothing to charge.
     *
     * A tip runs at the platform's ordinary commission unless a tip-specific rate is configured. The tax
     * rate and the amount both come from the caller, because both belong to the referenced product's
     * archetype, which this class deliberately does not know.
     *
     * @param  Money  $chosen  the gross amount the fan chose to give
     * @param  PlatformFee  $normalFee  the platform's ordinary commission, used unless a tip rate overrides it
     * @param  int  $taxBps  the referenced product's tax rate
     */
    public function tip(Money $chosen, PlatformFee $normalFee, int $taxBps): ?PricedSale
    {
        if (! $this->tipsEnabled()) {
            return null;
        }

        // Zero is no sale. Guarded first and explicitly, because everything downstream — the provider call,
        // the document, the reportable inflow — must simply not happen, not happen-with-zeros.
        if ($chosen->isZero()) {
            return null;
        }

        return $this->pricing->fromFanGross($chosen, $this->feeForTip($normalFee), $taxBps);
    }

    /**
     * Price a pay-what-you-want sale, enforcing the server-side floor.
     *
     * @throws FanPriceTooLow when the chosen amount is below the configured minimum
     */
    public function payWhatYouWant(Money $chosen, PlatformFee $fee, int $taxBps): ?PricedSale
    {
        if ($chosen->isZero()) {
            return null;
        }

        $minimum = $this->minimumFor($chosen->currency);

        // The floor is checked here rather than trusted from the client. A price the buyer picks is the one
        // place the package's anti-injection stance would otherwise lapse, so it is re-established on the
        // server from config.
        if ($chosen->lessThan($minimum)) {
            throw new FanPriceTooLow($chosen, $minimum);
        }

        return $this->pricing->fromFanGross($chosen, $fee, $taxBps);
    }

    /**
     * The commission applied to a tip: a tip-specific rate if configured, otherwise the ordinary one.
     *
     * Public because the charge path needs the SAME fee this class prices with. It was private, and that is
     * precisely why nothing could call this class: a caller could ask for a priced tip or it could charge
     * one, but it could not do both at the same rate — so the tip rate was a setting no sale could ever
     * carry. One place decides it, and both the preview and the money read it from here.
     */
    public function feeForTip(PlatformFee $normalFee): PlatformFee
    {
        $bps = $this->config->get('billing.marketplace.tips.commission_bps');

        if ($bps === null) {
            return $normalFee;
        }

        if (! is_int($bps) || $bps < 0 || $bps > 10_000) {
            throw InvalidBillingConfig::forKey(
                'billing.marketplace.tips.commission_bps',
                'must be null, or an integer between 0 and 10000',
            );
        }

        // A configured tip rate replaces BOTH the rate and the flat component; the rounding direction is
        // the only thing carried over. The zero is deliberate, not a dropped argument: a flat fee is a
        // fixed amount per transaction, so on a 1.00 tip against a 30-minor flat it would take almost a
        // third of a voluntary payment, and on a small enough tip more than the tip. The platform absorbs it.
        //
        // Note the asymmetry with the early return above, because it is the surprising part: with NO tip
        // rate configured the ordinary fee is returned whole, flat component included. So a tip carries the
        // flat fee exactly when the operator has said nothing about tips, and stops carrying it the moment
        // they set a rate. Both branches are pinned in FanChosenPricingTest — neither was, which is why
        // rewriting this line moved money on every tip and turned nothing red.
        return new PlatformFee($bps, 0, $normalFee->residual);
    }

    private function minimumFor(string $currency): Money
    {
        $value = $this->config->get('billing.marketplace.pwyw.minimum_minor', 0);

        if (! is_int($value) || $value < 0) {
            throw InvalidBillingConfig::forKey(
                'billing.marketplace.pwyw.minimum_minor',
                'must be a non-negative integer',
            );
        }

        return new Money($value, $currency);
    }
}
