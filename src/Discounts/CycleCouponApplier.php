<?php

declare(strict_types=1);

namespace Pushery\Billing\Discounts;

use Closure;
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
 * neither the customer nor the accounts can see why. So it becomes a negative line carrying the coupon's
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
 * The count is recorded against the period it discounted rather than incremented blindly, because pricing
 * happens before the order is claimed and a claim can lose: to a concurrent run, or to an order that
 * already exists. Counting attempts instead of cycles would burn a customer's remaining months on a run
 * that billed nothing.
 *
 * ## A coupon for some plans only
 *
 * A coupon that names plans takes its share from their lines alone: a percentage of their gross, and a fixed amount
 * up to it. A line priced from a subscription item belongs to the `plan_key` its metadata carries, and the plan line
 * of a subscription billed by its tier to the tier. The discount line names the plans, so a document can tell which
 * lines it reduced.
 *
 * ## A coupon meant before tax
 *
 * A `net_percent` coupon takes its share of the net of its lines, in hundredths of a percent and rounded half away from
 * zero to the minor unit, and a `net_fixed` one its amount off that net. The engine prices a cycle gross, so the line
 * takes off the gross that leaves the reduced net with its tax, at the rate the cycle is taxed at, which the engine
 * decides when such a coupon asks. The line keeps that rate in `tax_rate_bps`. Where no basis for the tax was
 * established, the cycle's document states no tax, and the coupon takes its share as though the gross were the net.
 */
final readonly class CycleCouponApplier
{
    /**
     * @param  list<OrderItemDraft>  $drafts
     * @param  (Closure(): ?int)|null  $rate  the rate the cycle is taxed at, in basis points, asked only by a coupon meant before tax
     * @return list<OrderItemDraft>
     */
    public function apply(array $drafts, Subscription $subscription, Model $owner, string $period, ?Closure $rate = null): array
    {
        return $this->discounted($drafts, $subscription, $owner, $period, true, $rate);
    }

    /**
     * The lines apply() would return, without counting the cycle.
     *
     * A preview of the next invoice prices the cycle before the cycle runs. Counting there would spend one of
     * the customer's discounted cycles on a figure shown on a screen: a coupon sold as three months half price
     * would lose a month each time somebody opened the subscription page.
     *
     * @param  list<OrderItemDraft>  $drafts
     * @param  (Closure(): ?int)|null  $rate  the rate the cycle is taxed at, in basis points, asked only by a coupon meant before tax
     * @return list<OrderItemDraft>
     */
    public function preview(array $drafts, Subscription $subscription, Model $owner, string $period, ?Closure $rate = null): array
    {
        return $this->discounted($drafts, $subscription, $owner, $period, false, $rate);
    }

    /**
     * @param  list<OrderItemDraft>  $drafts
     * @param  (Closure(): ?int)|null  $rate
     * @return list<OrderItemDraft>
     */
    private function discounted(array $drafts, Subscription $subscription, Model $owner, string $period, bool $count, ?Closure $rate): array
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

        $plans = $coupon->planKeys();
        $base = $plans === null ? $gross : $this->grossOf(array_values(array_filter(
            $drafts,
            fn (OrderItemDraft $draft): bool => in_array($this->planOf($draft, $subscription), $plans, true),
        )));

        // A coupon for plans the cycle carries no line of has nothing to take its share from.
        if (! $base instanceof Money || ! $base->isPositive()) {
            return $drafts;
        }

        $beforeTax = $coupon->isMeantBeforeTax();
        $rateBps = $beforeTax && $rate instanceof Closure ? $rate() : null;
        $reduction = $beforeTax ? $this->netReduction($coupon, $base, $rateBps ?? 0) : $this->grossReduction($coupon, $base);

        // Not a rounding guard for a coupon off the gross: any percentage of a positive gross takes at least one minor
        // unit off, and a fixed coupon of zero describes no discount and stops in discountFrom(). It is reached by a
        // fixed coupon denominated in another currency, which resolves to zero rather than being converted, since
        // converting here would invent an exchange rate on an invoice. A coupon meant before tax takes nothing off a
        // net too small for its share to round to a minor unit.
        if (! $reduction instanceof Money || ! $reduction->isPositive()) {
            return $drafts;
        }

        if ($count) {
            $this->countCycle($redemption, $period);
        }

        $drafts[] = new OrderItemDraft(
            "Discount ({$coupon->code})",
            -$reduction->minorUnits,
            1,
            $base->currency,
            OrderItemType::Discount,
            [
                'coupon' => $coupon->code,
                'duration' => $coupon->duration,
                'period' => $period,
                ...($plans === null ? [] : ['plan_keys' => $plans]),
                ...($beforeTax ? ['tax_rate_bps' => $rateBps] : []),
            ],
        );

        return $drafts;
    }

    /**
     * The plan a line bills: the key its metadata names, as the lines of a subscription's items carry it, or the tier
     * for the plan line a subscription without items is billed by.
     */
    private function planOf(OrderItemDraft $draft, Subscription $subscription): ?string
    {
        $key = $draft->metadata['plan_key'] ?? null;

        if (is_string($key)) {
            return $key;
        }

        return $draft->type === OrderItemType::Subscription ? $subscription->tier_key : null;
    }

    /**
     * Whether this coupon still has a cycle left to give.
     *
     * A period already counted stays eligible: re-pricing the same cycle must produce the same lines, or
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
            $currency = $this->currencyOf($coupon, $gross);

            return $currency === $gross->currency
                ? Discount::fixed($coupon->code, Money::of($coupon->value, $currency))
                : Discount::fixed($coupon->code, Money::zero($gross->currency));
        }

        return Discount::percentage($coupon->code, $coupon->value);
    }

    /** What a coupon off the gross takes off the gross of its lines, or null where the row describes no discount. */
    private function grossReduction(Coupon $coupon, Money $base): ?Money
    {
        $discount = $this->discountFrom($coupon, $base);

        return $discount instanceof Discount ? $base->minus($discount->applyTo($base)) : null;
    }

    /**
     * What a coupon meant before tax takes off the gross of its lines, or null where it takes nothing.
     *
     * The cycle's document splits the order's gross into the net and the tax at the cycle's rate. So the coupon takes
     * its share of the net of its lines, or its fixed amount up to that net, and the line takes off the gross whose split
     * leaves the net less the discount, with the tax of what is left. That is the figure an application arrives at that
     * discounts its net price before adding tax, to the minor unit. A fixed amount in another currency takes nothing
     * off, as one off the gross does.
     */
    private function netReduction(Coupon $coupon, Money $base, int $rateBps): ?Money
    {
        if (! $coupon->describesADiscount()) {
            return null;
        }

        [$net] = $base->baseFromMarkup($rateBps);

        $off = match (true) {
            $coupon->type === 'net_percent' => $net->proportion($coupon->value, 10_000),
            $this->currencyOf($coupon, $base) === $base->currency => Money::of(min($coupon->value, $net->minorUnits), $base->currency),
            default => Money::zero($base->currency),
        };

        if (! $off->isPositive()) {
            return null;
        }

        $left = $net->minus($off);

        return $base->minus($left->plus($left->proportion($rateBps, 10_000)));
    }

    /** The currency a fixed coupon is denominated in: its own, or the cycle's where the row names none. */
    private function currencyOf(Coupon $coupon, Money $base): string
    {
        return is_string($coupon->currency) && $coupon->currency !== '' ? $coupon->currency : $base->currency;
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
