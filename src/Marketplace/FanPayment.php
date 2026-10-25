<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Exceptions\FanPriceTooLow;
use Pushery\Billing\Tax\DistanceSaleThresholdMonitor;
use Pushery\Billing\Tax\SaleTaxDecision;
use Pushery\Billing\Tax\SaleTaxFacts;
use Pushery\Billing\ValueObjects\ChargeResult;
use Pushery\Billing\ValueObjects\ChargeRouting;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PlatformFee;
use Pushery\Billing\ValueObjects\TaxContext;

/**
 * The way a buyer-chosen amount actually becomes a sale.
 *
 * ## Why it exists
 *
 * Every part of this was already built and none of it could be reached. `FanChosenPricing` held the tip
 * commission rate, the pay-what-you-want floor and the refusal of a zero amount — and nothing in `src/`
 * called it, so all three were settings no sale could carry. `SaleTaxDecision` could place a tip by what it
 * was paid on, and no path handed it a buyer-chosen total. `RoutedPayment::charge()` has taken the archetype
 * and its reference since the classification gate landed, and no caller ever supplied a voluntary one.
 *
 * The missing piece was never arithmetic. It was the seam that puts those three in a row, in the one order
 * that is correct — decide the tax from what the tip was paid on, price at the rate that decision returns,
 * then charge through the single path that reaches the provider.
 *
 * ## Why the money is derived once, here and no earlier
 *
 * `FanChosenPricing` returns a priced sale, and it is tempting to hand that split to the charge. It is not
 * handed on. `RoutedPayment::charge()` derives the commission itself, from the same `Money::baseFromMarkup`
 * and the same `PlatformFee` — so passing a second copy would create two answers to one question with only
 * the second one stored. The pricing class is asked for its rules (is tipping on, what rate does a tip
 * carry, is this above the floor) and the charge is asked for the money. A preview for a screen is a
 * separate call, made where the screen is, and it agrees because it reads the same rate from the same place.
 *
 * ## What it refuses
 *
 * A zero amount, before the provider — a fan who leaves the tip box empty is the ordinary way to get there,
 * and a zero sale is a provider call, a charge row, an earnings figure and a line in a tax return, all
 * describing a sale nobody made. A pay-what-you-want price below the operator's floor, on the server,
 * because a buyer-chosen price is the one place this package's stance against price injection would
 * otherwise lapse. And a tip that does not say what it was paid on, which is refused further down by the
 * tax decision itself: a tip on commissioned work and a tip on a download are placed in different countries,
 * and neither is a safe guess.
 */
final readonly class FanPayment
{
    public function __construct(
        private FanChosenPricing $pricing,
        private SaleTaxDecision $taxes,
        private RoutedPayment $payments,
        /**
         * The installation's distance-sale threshold, which keeps a cross-border sale to a consumer at the platform's
         * rate while it holds. Null where the payment was built by hand, and then nothing watches it.
         */
        private ?DistanceSaleThresholdMonitor $thresholds = null,
    ) {}

    /**
     * Charge a tip, or return null when there is nothing to charge.
     *
     * @param  Money  $chosen  the gross amount the fan chose to give, tax included
     * @param  PlatformFee  $normalFee  the platform's ordinary commission; a configured tip rate overrides it
     * @param  TaxArchetype  $soldAlongside  what the tip was paid on — required, because a tip has no
     *                                       treatment of its own
     *
     * @throws FanPriceTooLow when the chosen amount is below the configured floor
     */
    public function tip(
        Model $merchant,
        Model $buyerOwner,
        Money $chosen,
        PlatformFee $normalFee,
        TaxContext $buyer,
        bool $buyerIsDomestic,
        string $token,
        ChargeRouting $routing,
        TaxArchetype $soldAlongside,
        ?string $idempotencyKey = null,
    ): ?ChargeResult {
        $facts = $this->tipFacts($chosen, $buyer, $soldAlongside);

        if (! $facts instanceof SaleTaxFacts) {
            return null;
        }

        return $this->payments->charge(
            $merchant,
            $buyerOwner,
            $chosen,
            $this->pricing->feeForTip($normalFee),
            $this->statedRate($facts),
            $buyerIsDomestic,
            $token,
            $routing,
            TaxArchetype::Tip,
            $soldAlongside,
            $idempotencyKey,
            // Where the buyer is and where the decision above placed the sale, so the receipt states the treatment the
            // rate came from rather than one derived again downstream.
            destinationCountry: $buyer->countryCode,
            placement: $facts->placement,
        );
    }

    /**
     * Charge a pay-what-you-want sale, or return null when nothing was chosen.
     *
     * Unlike a tip this is the product being sold, so it carries the product's own archetype and needs no
     * reference. The floor is the only thing that separates it from an ordinary sale priced backwards.
     *
     * @param  Money  $chosen  the gross price the fan chose, tax included
     *
     * @throws FanPriceTooLow when the chosen price is below the configured floor
     */
    public function payWhatYouWant(
        Model $merchant,
        Model $buyerOwner,
        Money $chosen,
        PlatformFee $fee,
        TaxContext $buyer,
        bool $buyerIsDomestic,
        string $token,
        ChargeRouting $routing,
        TaxArchetype $archetype,
        ?string $idempotencyKey = null,
    ): ?ChargeResult {
        $facts = $this->chosenPriceFacts($chosen, $fee, $buyer, $archetype);

        if (! $facts instanceof SaleTaxFacts) {
            return null;
        }

        return $this->payments->charge(
            $merchant,
            $buyerOwner,
            $chosen,
            $fee,
            $this->statedRate($facts),
            $buyerIsDomestic,
            $token,
            $routing,
            $archetype,
            null,
            $idempotencyKey,
            destinationCountry: $buyer->countryCode,
            placement: $facts->placement,
        );
    }

    /**
     * Take a tip from the fan's internal balance, or return null when there is nothing to take.
     *
     * The tip {@see self::tip()} charges, with the same floor, commission and receipt, paid through
     * {@see RoutedPayment::chargeFromBalance()}: the fan's balance is debited and the merchant's credited.
     *
     * @param  Money  $chosen  the gross amount the fan chose to give, tax included
     * @param  PlatformFee  $normalFee  the platform's ordinary commission; a configured tip rate overrides it
     * @param  TaxArchetype  $soldAlongside  what the tip was paid on
     * @param  string  $idempotencyKey  the key of this tip, stable across a retry of it
     *
     * @throws FanPriceTooLow when the chosen amount is below the configured floor
     */
    public function tipFromBalance(
        Model $merchant,
        Model $buyerOwner,
        Money $chosen,
        PlatformFee $normalFee,
        TaxContext $buyer,
        bool $buyerIsDomestic,
        TaxArchetype $soldAlongside,
        string $idempotencyKey,
    ): ?ChargeResult {
        $facts = $this->tipFacts($chosen, $buyer, $soldAlongside);

        if (! $facts instanceof SaleTaxFacts) {
            return null;
        }

        return $this->payments->chargeFromBalance(
            $merchant,
            $buyerOwner,
            $chosen,
            $this->pricing->feeForTip($normalFee),
            $this->statedRate($facts),
            $buyerIsDomestic,
            $idempotencyKey,
            TaxArchetype::Tip,
            $soldAlongside,
            destinationCountry: $buyer->countryCode,
            placement: $facts->placement,
        );
    }

    /**
     * Take a pay-what-you-want sale from the fan's internal balance, or return null when nothing was chosen.
     *
     * The sale {@see self::payWhatYouWant()} charges, with the same floor, paid through
     * {@see RoutedPayment::chargeFromBalance()}.
     *
     * @param  Money  $chosen  the gross price the fan chose, tax included
     * @param  string  $idempotencyKey  the key of this sale, stable across a retry of it
     *
     * @throws FanPriceTooLow when the chosen price is below the configured floor
     */
    public function payWhatYouWantFromBalance(
        Model $merchant,
        Model $buyerOwner,
        Money $chosen,
        PlatformFee $fee,
        TaxContext $buyer,
        bool $buyerIsDomestic,
        TaxArchetype $archetype,
        string $idempotencyKey,
    ): ?ChargeResult {
        $facts = $this->chosenPriceFacts($chosen, $fee, $buyer, $archetype);

        if (! $facts instanceof SaleTaxFacts) {
            return null;
        }

        return $this->payments->chargeFromBalance(
            $merchant,
            $buyerOwner,
            $chosen,
            $fee,
            $this->statedRate($facts),
            $buyerIsDomestic,
            $idempotencyKey,
            $archetype,
            destinationCountry: $buyer->countryCode,
            placement: $facts->placement,
        );
    }

    /**
     * The tax of a tip once it passed the switch and the floor, or null when there is nothing to charge.
     *
     * @throws FanPriceTooLow when the chosen amount is below the configured floor
     */
    private function tipFacts(Money $chosen, TaxContext $buyer, TaxArchetype $soldAlongside): ?SaleTaxFacts
    {
        // Both answers come from the class that owns them rather than being re-read from config here. A
        // second reader of a switch is a second place it can be read differently.
        if (! $this->pricing->tipsEnabled() || $chosen->isZero()) {
            return null;
        }

        // The tax first, because its rate is what the commission is then taken on — and because this is
        // where a tip stops being a tip and becomes whatever it was paid alongside.
        $facts = $this->decide(TaxArchetype::Tip, $chosen, $buyer, $soldAlongside);

        // Called for the floor, exactly as the pay-what-you-want lane below calls its pricing method for
        // one. This lane derives its own money, so the priced sale is not what is wanted here -- the
        // refusal is, and asking the class that owns the rule is what keeps one rule from becoming two.
        $this->pricing->assertTipMeetsMinimum($chosen);

        return $facts;
    }

    /**
     * The tax of a price the fan chose once it passed the floor, or null when nothing was chosen.
     *
     * @throws FanPriceTooLow when the chosen price is below the configured floor
     */
    private function chosenPriceFacts(Money $chosen, PlatformFee $fee, TaxContext $buyer, TaxArchetype $archetype): ?SaleTaxFacts
    {
        if ($chosen->isZero()) {
            return null;
        }

        $facts = $this->decide($archetype, $chosen, $buyer);

        // Called for the floor, which lives there and only there. What it returns is deliberately discarded:
        // the money is derived once, by the charge, and a split computed here as well would be the second
        // answer this package keeps having to hunt down. Raising `FanPriceTooLow` is the whole contribution.
        $this->pricing->payWhatYouWant($chosen, $fee, $this->statedRate($facts));

        return $facts;
    }

    /**
     * The rate a gross-priced sale's receipt states: the regime's statutory rate, and the quotient of the
     * two rounded amounts only where the regime named none.
     *
     * The receipt splits the gross by the rate it is handed and prints that rate beside the total. The
     * quotient of a small gross's rounded parts is no rate a country has, 192 on 1008 is 19.05 %, so
     * handing it on put a rate the law does not know on the document.
     */
    private function statedRate(SaleTaxFacts $facts): int
    {
        return $facts->appliedRateBps > 0 ? $facts->appliedRateBps : $facts->rateBps;
    }

    /**
     * The tax of a buyer-chosen amount, with the distance-sale threshold applied.
     *
     * Below the threshold (Art. 59c VAT Directive) a cross-border sale to a consumer that the product places at the
     * buyer stays taxed where the platform is. The year's total is asked with this sale's net at the platform's rate,
     * so the sale that crosses the threshold is taxed at the destination already, as an order's cycle is.
     */
    private function decide(TaxArchetype $archetype, Money $chosen, TaxContext $buyer, ?TaxArchetype $soldAlongside = null): SaleTaxFacts
    {
        $facts = $this->taxes->decideOnGross($archetype, $chosen, $buyer, soldAlongside: $soldAlongside);

        if (! $facts->reportableUnderOneStopShop() || ! $this->thresholds instanceof DistanceSaleThresholdMonitor) {
            return $facts;
        }

        $atThePlatform = $this->taxes->decideOnGross($archetype, $chosen, $buyer, soldAlongside: $soldAlongside, belowDistanceSaleThreshold: true);

        return $this->thresholds->keepsAtTheSeller(CarbonImmutable::now()->year, $chosen->currency, $chosen->minus($atThePlatform->tax)->minorUnits) ? $atThePlatform : $facts;
    }
}
