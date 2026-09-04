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

        $reduction = $gross->minus($this->discountFrom($coupon, $gross)->applyTo($gross));

        // Reachable through exactly one route, and it is worth naming because the guard looks generic: a
        // FIXED coupon denominated in another currency resolves to zero rather than being converted, since
        // converting here would invent an exchange rate on an invoice. A percentage of zero cannot get
        // this far — `Discount` refuses it — so this is not a rounding guard, it is the cross-currency one.
        if (! $reduction->isPositive()) {
            return $drafts;
        }

        $this->countCycle($redemption, $period);

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
     * The coupon as a discount.
     *
     * A fixed-amount coupon scoped to another currency is refused rather than converted: the package holds
     * no exchange rate, and applying "5 off" across currencies would invent one.
     */
    private function discountFrom(Coupon $coupon, Money $gross): Discount
    {
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
        return CouponRedemption::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            // Typed, because the type-coverage floor counts a closure parameter like any other — and an
            // untyped one here is not merely a formality: the grouped WHERE is what keeps the two clauses
            // together, and reading `$query` as anything at all is how somebody later replaces the group
            // with a flat `orWhere` that ORs against the whole query instead of within the group.
            ->where(function (Builder $query) use ($subscription): void {
                $query->whereNull('subscription_id')->orWhere('subscription_id', $subscription->getKey());
            })
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
