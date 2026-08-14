<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\MerchantAccountDirectory;
use Pushery\Billing\Contracts\PlatformFeeResolver;
use Pushery\Billing\Contracts\SellerOfRecordResolver;
use Pushery\Billing\Drivers\Stripe\StripeCheckout;
use Pushery\Billing\Drivers\Stripe\StripeOneTimeCharge;
use Pushery\Billing\Enums\ChargeType;
use Pushery\Billing\Exceptions\MerchantPartyUnavailable;
use Pushery\Billing\ValueObjects\ChargeRouting;
use Pushery\Billing\ValueObjects\MerchantAccountReference;
use Pushery\Billing\ValueObjects\Money;

/**
 * Assembles a routed payment's routing and checks the pairing while doing it.
 *
 * ## What this does NOT claim, and used to
 *
 * It said it was "the one place a routed payment is assembled — and therefore the one place the pairing is
 * checked", and that a routing "is not constructible on the ordinary path except through here". Neither
 * holds, and the sentences mattered more than most: they described where a MONEY-FLOW guard is enforced,
 * and they described it in the wrong place.
 *
 *  - {@see ChargeRouting}'s constructor is public and validates only that the fee is not negative. It knows
 *    nothing of {@see ChargeType} or the seller posture.
 *  - This class has no caller in `src/` at all — the register carries it as pending. The "ordinary path"
 *    does not come through this door.
 *  - `BillingAdmin` builds a routing directly, and correctly: its lane comes from the STORED row, so
 *    checking it against today's posture would refuse a reversal for a sale that was legal when it was made.
 *
 * ## Where the pairing is actually enforced
 *
 * At the three seams that reach a provider — {@see RoutedPayment::charge()},
 * {@see StripeCheckout} and
 * {@see StripeOneTimeCharge} — each calling
 * {@see ChargeRoutingConsistencyGuard::assertCompatible()} before anything is sent. That placement is the
 * real property worth having, and it is the one this docblock should have been describing: the refusal
 * lands before a charge is made, not after, when only the transfer is left to fail.
 *
 * The charge type is configuration because it is a money-flow decision an installation makes with its
 * provider, and the posture is resolved rather than passed, so a caller cannot quietly hand in the one that
 * would make its own pairing legal.
 */
final readonly class ChargeRoutingResolver
{
    public function __construct(
        private MarketplaceSaleContext $context,
        private ChargeRoutingConsistencyGuard $guard,
        private SellerOfRecordResolver $postures,
        private MerchantAccountDirectory $accounts,
        private PlatformFeeResolver $fees,
    ) {}

    /**
     * The routing for one payment to one merchant.
     *
     * @param  Money  $gross  what the buyer pays
     * @param  int  $taxBps  the tax on the buyer's side, in basis points. Required rather than defaulted,
     *                       because the fee assembled here becomes the provider's application fee — and a
     *                       default of zero would go on charging it against the gross for every caller that
     *                       had not been updated, which is silent and is money.
     * @param  bool  $suppliesAreElectronic  what is being sold, which is what the posture turns on
     */
    public function resolveFor(Model $merchant, Money $gross, int $taxBps, bool $suppliesAreElectronic = true): ChargeRouting
    {
        $type = $this->context->chargeType();
        $posture = $this->postures->resolveFor($suppliesAreElectronic);

        // First, before anything is assembled and long before anything is sent.
        $this->guard->assertCompatible($type, $posture);

        $account = $this->accounts->accountFor($merchant);

        if (! $account instanceof MerchantAccountReference) {
            throw MerchantPartyUnavailable::forMerchant($merchant);
        }

        // On the NET, which is what the configured rate has always claimed to be and what the pricing path
        // has always done. Taken here on the payment, the commission included the buyer's tax — and this
        // figure is the one handed to the provider as the application fee, so the difference is not a
        // reporting discrepancy but money the merchant was not paid.
        $fee = $this->fees->feeFor($merchant)->of($gross->baseFromMarkup($taxBps)[0]);

        return new ChargeRouting($account, $fee, $type);
    }
}
