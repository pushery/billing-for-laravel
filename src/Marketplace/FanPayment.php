<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Exceptions\FanPriceTooLow;
use Pushery\Billing\Tax\SaleTaxDecision;
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
 * that is correct — decide the tax from what the tip was paid ON, price at the rate that decision returns,
 * then charge through the single path that reaches the provider.
 *
 * ## Why the money is derived once, here and no earlier
 *
 * `FanChosenPricing` returns a priced sale, and it is tempting to hand that split to the charge. It is not
 * handed on. `RoutedPayment::charge()` derives the commission itself, from the same `Money::baseFromMarkup`
 * and the same `PlatformFee` — so passing a second copy would create two answers to one question with only
 * the second one stored. The pricing class is asked for its RULES (is tipping on, what rate does a tip
 * carry, is this above the floor) and the charge is asked for the money. A preview for a screen is a
 * separate call, made where the screen is, and it agrees because it reads the same rate from the same place.
 *
 * ## What it refuses
 *
 * A zero amount, before the provider — a fan who leaves the tip box empty is the ordinary way to get there,
 * and a zero sale is a provider call, a charge row, an earnings figure and a line in a tax return, all
 * describing a sale nobody made. A pay-what-you-want price below the operator's floor, on the SERVER,
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
    ) {}

    /**
     * Charge a tip, or return null when there is nothing to charge.
     *
     * @param  Money  $chosen  the gross amount the fan chose to give, tax included
     * @param  PlatformFee  $normalFee  the platform's ordinary commission; a configured tip rate overrides it
     * @param  TaxArchetype  $soldAlongside  what the tip was paid ON — required, because a tip has no
     *                                       treatment of its own
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
        // Both answers come from the class that owns them rather than being re-read from config here. A
        // second reader of a switch is a second place it can be read differently.
        if (! $this->pricing->tipsEnabled() || $chosen->isZero()) {
            return null;
        }

        // The tax FIRST, because its rate is what the commission is then taken on — and because this is
        // where a tip stops being a tip and becomes whatever it was paid alongside.
        $facts = $this->taxes->decideOnGross(
            TaxArchetype::Tip,
            $chosen,
            $buyer,
            soldAlongside: $soldAlongside,
        );

        return $this->payments->charge(
            $merchant,
            $buyerOwner,
            $chosen,
            $this->pricing->feeForTip($normalFee),
            $facts->rateBps,
            $buyerIsDomestic,
            $token,
            $routing,
            TaxArchetype::Tip,
            $soldAlongside,
            $idempotencyKey,
        );
    }

    /**
     * Charge a pay-what-you-want sale, or return null when nothing was chosen.
     *
     * Unlike a tip this IS the product being sold, so it carries the product's own archetype and needs no
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
        if ($chosen->isZero()) {
            return null;
        }

        $facts = $this->taxes->decideOnGross($archetype, $chosen, $buyer);

        // Called for the floor, which lives there and only there. What it returns is deliberately discarded:
        // the money is derived once, by the charge, and a split computed here as well would be the second
        // answer this package keeps having to hunt down. Raising `FanPriceTooLow` is the whole contribution.
        $this->pricing->payWhatYouWant($chosen, $fee, $facts->rateBps);

        return $this->payments->charge(
            $merchant,
            $buyerOwner,
            $chosen,
            $fee,
            $facts->rateBps,
            $buyerIsDomestic,
            $token,
            $routing,
            $archetype,
            null,
            $idempotencyKey,
        );
    }
}
