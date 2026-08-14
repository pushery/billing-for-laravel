<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\SupplyRegimeResolver;
use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Exceptions\BuyerFeeNotApplicable;
use Pushery\Billing\Exceptions\InvalidBillingConfig;
use Pushery\Billing\ValueObjects\FeeLine;
use Pushery\Billing\ValueObjects\Money;

/**
 * Whether a buyer fee applies to THIS sale — the two conditions, asked together.
 *
 * ## Why the calculator could not answer this on its own
 *
 * {@see BuyerFeeCalculator} reads configuration and does arithmetic. It knows the model, the rate and the
 * rounding, and it correctly knows nothing about the sale it is being asked about. That is the whole reason
 * it had no caller: something has to decide whether this particular sale is one a buyer fee belongs to, and
 * the answer is not in the config file.
 *
 * ## The two conditions, and they are independent
 *
 * **The adopter's choice.** `billing.marketplace.buyer_fee.enabled`, off by default. Whether a platform
 * charges buyers a fee is not this package's call to make — it is the adopting developer's, and it has to
 * be selectable rather than assumed either way.
 *
 * **The regime.** A buyer fee is the platform's own intermediation supply to the buyer — its first supply in
 * the intermediary posture. That is a fact about `intermediation` sales, and it is simply false under a
 * commission chain: there the platform IS the seller, it sells to the buyer directly, and there is no
 * mediation to charge for. A fee booked there as intermediation revenue would invent a supply relationship
 * that does not exist.
 *
 * That is not an abstract worry. It is the mirror image of a defect this package had just finished removing
 * from its reporting record, where a withheld fee was reported in the one regime that has none. Building
 * the same mistake on the buyer's side would have been hard to defend.
 *
 * ## Enabled in the wrong regime REFUSES — it does not quietly charge nothing
 *
 * Three outcomes were possible for that combination, and quietly charging nothing is the worst of them: the
 * switch reads as on, the developer believes they are collecting, and the first evidence otherwise is
 * revenue that never arrived. Refusing says which of the two conditions failed, at the point where it can
 * still be fixed for free.
 */
final readonly class ChargedBuyerFee
{
    public function __construct(
        private BuyerFeeCalculator $fees,
        private SupplyRegimeResolver $regimes,
        private Repository $config,
    ) {}

    /**
     * The fee this sale carries, or null when no fee applies to it.
     *
     * @param  Money  $saleGross  the price of the mediated item — never the total the buyer will pay, which
     *                            is this fee ON TOP of that
     * @param  int  $taxBps  the rate for this supply at its place
     *
     * @throws BuyerFeeNotApplicable when the developer switched fees on for a sale whose regime has none
     * @throws InvalidBillingConfig when fees apply and nothing says where the mediated sale happened
     */
    public function on(Money $saleGross, int $taxBps, ?TaxArchetype $archetype = null): ?FeeLine
    {
        // The developer's switch is read FIRST, and that ordering matters: an installation that never
        // enabled buyer fees must not have its regime resolved, refused, or even consulted on their
        // account. Off is off, and it costs nothing.
        if (! $this->fees->isEnabled()) {
            return null;
        }

        $regime = $this->regimes->resolveFor($archetype);

        if ($regime !== SupplyRegime::Intermediation) {
            throw BuyerFeeNotApplicable::inRegime($regime);
        }

        return $this->fees->feeFor($saleGross, $this->placeOfSupply(), $taxBps);
    }

    /**
     * Where the mediated sale happened — the fee's place of supply, and NOT where the buyer banks.
     *
     * ## Asked LAST, and that ordering is the point
     *
     * You only need to know where a sale happened if you are actually charging for having mediated it. An
     * installation with fees switched off never reaches this, and neither does one whose regime has no such
     * supply — so requiring the setting costs exactly the installations that charge, and nobody else.
     *
     * ## It refuses rather than deriving one
     *
     * An earlier draft fell back to the sale's currency — `EUR` to `EU`. That is not a country, twenty of
     * them share that currency, and a rate keyed on it would be tax computed for a place that does not
     * exist. It would also have looked entirely ordinary in a payload, which is what makes it the
     * dangerous kind of wrong.
     */
    private function placeOfSupply(): string
    {
        $configured = $this->config->get('billing.marketplace.buyer_fee.place_of_supply');

        if (! is_string($configured) || trim($configured) === '') {
            throw InvalidBillingConfig::forKey(
                'billing.marketplace.buyer_fee.place_of_supply',
                'must name the country the mediated sale happens in, because the buyer fee carries that '
                ."country's rate. It cannot be derived from a currency — twenty countries share the euro",
            );
        }

        return strtoupper(trim($configured));
    }
}
