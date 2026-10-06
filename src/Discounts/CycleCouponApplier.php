<?php

declare(strict_types=1);

namespace Pushery\Billing\Discounts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\OrderItemType;
use Pushery\Billing\Models\Coupon;
use Pushery\Billing\Models\CouponRedemption;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\Discount;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\OrderItemDraft;

/**
 * Puts a redeemed coupon onto the cycle it discounts, as a line of its own.
 *
 * A discount that only shrinks the amount charged is invisible: the invoice shows a smaller number and
 * neither the customer nor the accounts can see WHY. So it becomes a negative line carrying the coupon's
 * code, and the order total is the sum of the lines — including this one.
 *
 * ## The order of operations, which is a decision and not an accident
 *
 * Discount, then tax, then credit. The discount comes first because it changes what was actually sold —
 * a plan bought at half price was sold for half the price, and taxing the full one would collect tax on
 * money nobody paid. Credit comes last because it is not a price at all: it is payment, already the
 * customer's, and applying it before the discount would spend their balance on an amount they never owed.
 *
 * ## Why a coupon's remaining cycles are counted here and not at redemption
 *
 * `duration` says `once`, `repeating` or `forever`, but until something counts, the last two are the same
 * thing. A coupon sold as "three months half price" discounted every invoice for the life of the
 * subscription, and the mistake ran in the customer's favor — which is the kind nobody reports.
 *
 * The count is recorded against the PERIOD it discounted rather than incremented blindly, because pricing
 * happens before the order is claimed and a claim can lose: to a concurrent run, or to an order that
 * already exists. Counting attempts instead of cycles would burn a customer's remaining months on a run
 * that billed nothing.
 */
final readonly class CycleCouponApplier
{
    /**
     * @param  list<OrderItemDraft>  $drafts
     * @return list<OrderItemDraft>
     */
    public function apply(array $drafts, Subscription $subscription, Model $owner, string $period): array
    {
        return $this->discounted($drafts, $subscription, $owner, $period, true);
    }

    /**
     * The lines apply() would return, without counting the cycle.
     *
     * A preview of the next invoice prices the cycle before the cycle runs. Counting there would spend one of
     * the customer's discounted cycles on a figure shown on a screen: a coupon sold as three months half price
     * would lose a month each time somebody opened the subscription page.
     *
     * @param  list<OrderItemDraft>  $drafts
     * @return list<OrderItemDraft>
     */
    public function preview(array $drafts, Subscription $subscription, Model $owner, string $period): array
    {
        return $this->discounted($drafts, $subscription, $owner, $period, false);
    }

    /**
     * @param  list<OrderItemDraft>  $drafts
     * @return list<OrderItemDraft>
     */
    private function discounted(array $drafts, Subscription $subscription, Model $owner, string $period, bool $count): array
    {
        $gross = $this->grossOf($drafts);

        if (! $gross instanceof Money || ! $gross->isPositive()) {
            return $drafts;
        }

        $redemption = $this->redemptionFor($owner, $subscription);

        if (! $redemption instanceof CouponRedemption) {
            return $drafts;
        }

        $coupon = $redemption->coupon;

        if (! $coupon instanceof Coupon || ! $this->stillRuns($coupon, $redemption, $period)) {
            return $drafts;
        }

        $discount = $this->discountFrom($coupon, $gross);

        if (! $discount instanceof Discount) {
            return $drafts;
        }

        $reduction = $gross->minus($discount->applyTo($gross));

        // Not a rounding guard: any percentage of a positive gross takes at least one minor unit off, and a fixed
        // coupon of zero describes no discount and stops in discountFrom(). It is reached by a FIXED coupon
        // denominated in another currency, which resolves to zero rather than being converted, since converting
        // here would invent an exchange rate on an invoice.
        if (! $reduction->isPositive()) {
            return $drafts;
        }

        if ($count) {
            $this->countCycle($redemption, $period);
        }

        $drafts[] = new OrderItemDraft(
            "Discount ({$coupon->code})",
            -$reduction->minorUnits,
            1,
            $gross->currency,
            OrderItemType::Discount,
            ['coupon' => $coupon->code, 'duration' => $coupon->duration, 'period' => $period],
        );

        return $drafts;
    }

    /**
     * Whether this coupon still has a cycle left to give.
     *
     * A period already counted stays eligible: re-pricing the SAME cycle must produce the same lines, or
     * a retried run would quietly bill the customer more than the first attempt would have.
     */
    private function stillRuns(Coupon $coupon, CouponRedemption $redemption, string $period): bool
    {
        if ($coupon->active === false) {
            return false;
        }

        $expires = $coupon->expires_at;

        if ($expires !== null && Carbon::instance($expires)->isPast()) {
            return false;
        }

        if ($redemption->last_applied_period === $period) {
            return true;
        }

        return match ($coupon->duration) {
            'once' => $redemption->applied_count < 1,
            'repeating' => $redemption->applied_count < max(1, $coupon->duration_in_cycles ?? 1),
            'forever' => true,
            default => false,
        };
    }

    private function countCycle(CouponRedemption $redemption, string $period): void
    {
        if ($redemption->last_applied_period === $period) {
            return;
        }

        $redemption->forceFill([
            'applied_count' => $redemption->applied_count + 1,
            'last_applied_period' => $period,
        ])->save();
    }

    /**
     * The coupon as a discount, or null when the row describes none.
     *
     * A fixed-amount coupon scoped to another currency is refused rather than converted: the package holds
     * no exchange rate, and applying "5 off" across currencies would invent one.
     *
     * Whether the row is a discount at all is {@see Coupon::describesADiscount()}, the rule the local driver's
     * coupon question and the redemption of a carried code read too. The row is written by the application, so
     * a percentage of 0 or 150, or a type of `percentage`, is a row this cycle can meet: it bills the full
     * price, as the customer was told when the code was refused, rather than failing every time the
     * subscription comes due.
     */
    private function discountFrom(Coupon $coupon, Money $gross): ?Discount
    {
        if (! $coupon->describesADiscount()) {
            return null;
        }

        if ($coupon->type === 'fixed') {
            $currency = is_string($coupon->currency) && $coupon->currency !== '' ? $coupon->currency : $gross->currency;

            return $currency === $gross->currency
                ? Discount::fixed($coupon->code, Money::of($coupon->value, $currency))
                : Discount::fixed($coupon->code, Money::zero($gross->currency));
        }

        return Discount::percentage($coupon->code, $coupon->value);
    }

    private function redemptionFor(Model $owner, Subscription $subscription): ?CouponRedemption
    {
        return CouponRedemption::model()::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            // Typed, because the type-coverage floor counts a closure parameter like any other — and an
            // untyped one here is not merely a formality: the grouped WHERE is what keeps the two clauses
            // together, and reading `$query` as anything at all is how somebody later replaces the group
            // with a flat `orWhere` that ORs against the whole query instead of within the group.
            ->where(function (Builder $query) use ($subscription): void {
                $query->whereNull('subscription_id')->orWhere('subscription_id', $subscription->getKey());
            })
            // Only this contract's. A row is reused when its owner subscribes again, and `started_at` is where the
            // new contract begins: a coupon redeemed for the one before ended with it, a forever one included. Both
            // starts write the row first and redeem after, so this contract's own redemption is never earlier.
            ->when($subscription->started_at !== null, static fn (Builder $query): Builder => $query->where('redeemed_at', '>=', $subscription->started_at))
            ->orderByDesc('redeemed_at')
            ->with('coupon')
            ->first();
    }

    /** @param  list<OrderItemDraft>  $drafts */
    private function grossOf(array $drafts): ?Money
    {
        $sum = 0;
        $currency = null;

        foreach ($drafts as $draft) {
            $sum += $draft->totalMinor();
            $currency ??= $draft->currency;
        }

        return $currency === null ? null : Money::of(max(0, $sum), $currency);
    }
}
