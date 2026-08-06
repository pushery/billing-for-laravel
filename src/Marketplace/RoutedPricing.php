<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Pushery\Billing\Enums\PricingMode;
use Pushery\Billing\Enums\RoundingResidual;
use Pushery\Billing\Exceptions\InvalidBillingConfig;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PlatformFee;
use Pushery\Billing\ValueObjects\PricedSale;

/**
 * Prices a sale from what the creator wants to be paid, not from what the buyer should pay.
 *
 * The direction matters beyond convenience. A creator who names a fan price cannot see what a change in
 * their own tax standing does to their income — the price stays put and the payout moves underneath them.
 * Naming the payout instead makes that visible: the buyer's price is what moves, and it moves for a reason
 * anybody can point at.
 *
 * Two things are computed as differences rather than as second calculations, and both for the same reason:
 * a figure computed twice can disagree with itself by a cent, and nothing downstream can tell which of the
 * two is the sale. The tax is the gross minus the net, never the net times the rate. The payout is the net
 * minus the fee, never a separate percentage of the net.
 */
final readonly class RoutedPricing
{
    public function __construct(private Repository $config) {}

    /**
     * Price a sale so the creator receives about what they asked for.
     *
     * "About" is exact language. Under the decided rounding order the residual cent goes to the platform,
     * so a target that does not divide evenly comes back one cent short — 50.00 at 15% is paid as 49.99.
     * The result carries both figures so a screen can show what will actually be paid rather than repeating
     * the request back as though it were a promise.
     *
     * @param  int  $taxBps  the tax rate on the buyer's side, in basis points — 1900 is 19%
     */
    public function fromTargetPayout(Money $targetPayout, PlatformFee $fee, int $taxBps): PricedSale
    {
        if ($targetPayout->isNegative()) {
            throw new InvalidArgumentException('A target payout cannot be negative.');
        }

        if ($taxBps < 0) {
            throw new InvalidArgumentException("A tax rate cannot be negative; got {$taxBps}.");
        }

        // Up from the payout to the sale it has to come out of, then up again to what the buyer pays.
        // baseFromRate(0) is the identity, so a zero commission needs no special case — reversing a zero
        // rate returns the amount unchanged.
        $transactionNet = $targetPayout
            ->plus(new Money($fee->flatMinor, $targetPayout->currency))
            ->baseFromRate($fee->bps)[0];

        $fanGross = $this->grossFrom($transactionNet, $taxBps);

        return $this->priceOf($fanGross, $targetPayout, $fee, $taxBps);
    }

    /**
     * Price a sale whose buyer price is already fixed.
     *
     * The default shape: one price across every market, so the buyer's country moves the tax and therefore
     * the creator's payout rather than the price on the page. The creator sees the difference per position
     * on their statement instead of as an unexplained total.
     */
    public function fromFanGross(Money $fanGross, PlatformFee $fee, int $taxBps): PricedSale
    {
        return $this->priceOf($fanGross, null, $fee, $taxBps);
    }

    /** Which quantity a consumer holds fixed across markets. */
    public function mode(): PricingMode
    {
        $value = $this->config->get('billing.marketplace.pricing.mode', PricingMode::UniformGross->value);

        if (! is_string($value) || PricingMode::tryFrom($value) === null) {
            throw InvalidBillingConfig::forKey(
                'billing.marketplace.pricing.mode',
                "must be 'uniform_gross' or 'uniform_payout'",
            );
        }

        return PricingMode::from($value);
    }

    /**
     * The whole split of a sale whose buyer price is known.
     *
     * @param  ?Money  $target  what the creator asked for, when the sale was priced from one
     */
    private function priceOf(Money $fanGross, ?Money $target, PlatformFee $fee, int $taxBps): PricedSale
    {
        // Tax as the DIFFERENCE. At 7% on 119.00 the base is 111.21 and the tax is 7.79; computing it as
        // base × rate gives 7.78 and loses a cent that the buyer nonetheless paid. baseFromMarkup(0)
        // returns [gross, 0], so a tax-free market needs no branch of its own.
        [$net, $tax] = $fanGross->baseFromMarkup($taxBps);

        [$platformFee, $payout] = $fee->splitOf($net);

        return new PricedSale(
            targetPayout: $target ?? $payout,
            fanGross: $fanGross,
            transactionNet: $net,
            tax: $tax,
            platformFee: $platformFee,
            merchantPayout: $payout,
        );
    }

    /**
     * The buyer's price for a net amount at a tax rate.
     *
     * The tax is the rate's share OF THE NET added on top, taken with the same bps primitive the
     * commission uses, so no float is constructed anywhere on the money path.
     */
    private function grossFrom(Money $net, int $taxBps): Money
    {
        // splitByBps(0) is a zero portion, so net + 0 is net: a tax-free rate needs no early return, the
        // primitive already returns the net unchanged.
        return $net->plus($net->splitByBps($taxBps, RoundingResidual::ToPortion)[0]);
    }
}
