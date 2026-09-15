<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\MerchantResolver;
use Pushery\Billing\Contracts\SellerOfRecordResolver;
use Pushery\Billing\Enums\ChargeType;
use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Exceptions\MarketNotOpen;
use Pushery\Billing\Tax\TaxCalculatorFactory;
use Pushery\Billing\ValueObjects\PlatformFee;

/**
 * What this installation is, as a marketplace, for one sale — asked once instead of derived in each lane.
 *
 * ## Why this exists, and it is not tidiness
 *
 * Four derivations stood byte-identical across `StripeCheckout`, `StripeOneTimeCharge` and `RoutedPayment`:
 * whether marketplace routing is on and who the merchant is, which charge type this installation uses,
 * whether the provider computes the tax, and whether the supplies are electronic. Each lane read the same
 * keys and reached the same answer, independently.
 *
 * That held right up until one of them stopped. The one-off lane hard-wired `resolveFor(true)` where the
 * subscription lane passed the configured value, so an operator selling NON-electronic goods through a
 * marketplace where the merchant is the seller of record could open a subscription and could not complete a
 * one-off purchase — and the refusal they got named an electronic supply, which their own configuration
 * denies. Nothing was red: both lanes were internally consistent, and no test compared them.
 *
 * That specific divergence was closed on its own, ahead of this pooling and deliberately so — a legal
 * misstatement is not something to hold behind a refactor. What this class removes is the CONDITION that
 * produced it. One reader cannot disagree with itself.
 *
 * ## What belongs here and what does not
 *
 * Here: the answers a lane derives from configuration about the shape of the sale. Not here: the routed
 * charge itself — the destination account, the platform fee, the resolved routing. That assembly is
 * `ChargeRoutingResolver`'s, it is the subject of its own ticket, and duplicating a slice of it here would
 * recreate exactly the split this class exists to end.
 */
final readonly class MarketplaceSaleContext
{
    public function __construct(
        private Repository $config,
        private MerchantResolver $merchants,
        private SellerOfRecordResolver $postures,
        private ChargeRoutingConsistencyGuard $routingGuard,
        private MarketAllowlist $markets,
        /**
         * The buyer-chosen rules, pooled here rather than taken by each lane that needs them.
         *
         * The two methods below are DELEGATIONS and deliberately hold no logic: whether tipping is on and
         * what a tip costs are `FanChosenPricing`'s to answer, and a second reader of either would be a
         * second place the same switch can be read differently. What this class contributes is that a lane
         * asks ONE object for the answers it would otherwise assemble for itself — the same reason the four
         * readers above were pooled here, and the reason the lanes' constructors have a ratchet on them.
         */
        private FanChosenPricing $fanPricing,
    ) {}

    /** Whether this installation takes voluntary payments at all. Answered by the class that owns it. */
    public function tipsEnabled(): bool
    {
        return $this->fanPricing->tipsEnabled();
    }

    /** The commission a TIP carries, which is not always the platform's ordinary one. */
    public function tipFee(PlatformFee $normalFee): PlatformFee
    {
        return $this->fanPricing->feeForTip($normalFee);
    }

    /**
     * Refuse a sale into a country the operator has not opened, where the caller already knows the country.
     *
     * One of the answers a lane used to be about to derive for itself, so it is asked here once. A null country
     * checks nothing: a hosted checkout learns the buyer's address only on the provider's page, and what the buyer
     * enters there is checked once the provider reports where it taxed the sale.
     *
     * @throws MarketNotOpen
     */
    public function assertMarketOpen(?string $buyerCountry): void
    {
        if ($buyerCountry !== null) {
            $this->markets->assertOpen($buyerCountry);
        }
    }

    /**
     * The merchant this sale routes to, or null where this installation does not route at all.
     *
     * The switch is checked BEFORE the resolver is asked, not after. A resolver consulted on a
     * non-marketplace installation would be answering a question nobody asked, and whatever it returned
     * would then have to be discarded by a caller that could just as easily forget to.
     */
    public function routedMerchant(): ?Model
    {
        if ($this->config->get('billing.marketplace.enabled', false) !== true) {
            return null;
        }

        return $this->merchants->current();
    }

    /** Which money flow this installation uses with its provider. */
    public function chargeType(): ChargeType
    {
        return new ConfiguredChargeType($this->config)->get();
    }

    /** Whether the payment provider computes the tax, rather than this package. */
    public function providerTax(): bool
    {
        return in_array($this->config->get('billing.tax'), TaxCalculatorFactory::PROVIDER_MODES, true);
    }

    /**
     * Whether what this installation sells is electronically supplied.
     *
     * Defaulting to TRUE is the careful direction rather than the common one: an electronic supply is the
     * case the seller-of-record rules constrain, so an installation that has said nothing is held to the
     * stricter reading instead of quietly granted the looser one.
     */
    public function electronic(): bool
    {
        return (bool) $this->config->get('billing.marketplace.seller_of_record.supplies_are_electronic', true);
    }

    /** Who the tax law treats as the seller for a sale of this nature. */
    public function posture(): SellerOfRecordPosture
    {
        return $this->postures->resolveFor($this->electronic());
    }

    /**
     * Refuse a charge type and a posture that cannot both be true of one sale.
     *
     * The charge type and the posture are independent axes that must agree, and this is asked BEFORE
     * anything is assembled — the only point at which refusing is still free. Both Stripe lanes wrote this
     * line themselves, each deriving both halves from its own copy of the readers above; that is the shape
     * the divergence took, so the call moves here with them.
     */
    public function assertRoutingCompatible(ChargeType $type): void
    {
        $this->routingGuard->assertCompatible($type, $this->posture());
    }
}
