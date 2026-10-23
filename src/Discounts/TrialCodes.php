<?php

declare(strict_types=1);

namespace Pushery\Billing\Discounts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Exceptions\CouponUnavailable;
use Pushery\Billing\Exceptions\TrialCodeRefused;
use Pushery\Billing\Models\Coupon;
use Pushery\Billing\Models\CouponRedemption;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * Codes that give a subscription days of trial: coupons whose `trial_days` name the plan.
 *
 * The days count only where the code is redeemed, under the coupon's row lock, so of two orders racing for its last
 * redemption one gets the trial and the other none. That happens in the transaction that writes the subscription: at
 * the start for a subscription that needs no payment method, when the mandate settles for one that does. An order
 * that never gets that far holds no redemption, so nothing has to be given back.
 *
 * A code is honored for an owner who had a trial before, because winning a customer back is what such a code is for.
 */
final readonly class TrialCodes
{
    public function __construct(private CouponRedeemer $coupons) {}

    /**
     * The days of trial the code gives a subscription to the tier, for a form that checks a code before the
     * subscription starts.
     *
     * Every code that gives nothing is refused the same way, whatever the reason: unknown, withdrawn, past its last
     * day, used up, issued by a seller, or without days for the plan. Different answers would tell a stranger which
     * codes exist.
     *
     * @throws TrialCodeRefused when the code gives none
     */
    public function assertGives(string $code, string $tierKey): int
    {
        $coupon = $this->giving($code, $tierKey);

        if (! $coupon instanceof Coupon || ($coupon->max_redemptions !== null && $coupon->redeemed_count >= $coupon->max_redemptions)) {
            throw new TrialCodeRefused;
        }

        return $coupon->trialDaysFor($tierKey) ?? throw new TrialCodeRefused;
    }

    /**
     * The days of trial the code gives a subscription to the tier, without redeeming it, or null when it gives none.
     *
     * A caller asks this first and redeems only a code that lengthens the trial the subscription would get anyway, so
     * a customer does not spend the code on days the policy already gives.
     */
    public function daysFor(?string $code, string $tierKey): ?int
    {
        return $code === null || $code === '' ? null : $this->giving($code, $tierKey)?->trialDaysFor($tierKey);
    }

    /**
     * Redeems the code for the owner when it gives the tier days of trial, and answers the days with the redemption.
     *
     * Null when the code gives none or cannot be redeemed any more: a code never blocks a subscription, which then
     * starts with the trial its policy gives. The redemption carries no subscription yet; the caller writes the
     * subscription in the same transaction and attaches it.
     *
     * @return array{days: int, redemption: CouponRedemption}|null
     */
    public function redeemFor(Model $owner, ?string $code, string $tierKey): ?array
    {
        $coupon = $code === null || $code === '' ? null : $this->giving($code, $tierKey);
        $days = $coupon?->trialDaysFor($tierKey);

        if (! $coupon instanceof Coupon || $days === null) {
            return null;
        }

        try {
            return ['days' => $days, 'redemption' => $this->coupons->redeem($coupon, $owner)];
        } catch (CouponUnavailable) {
            return null;
        }
    }

    /** The live platform coupon the code names, when it gives the tier days of trial. */
    private function giving(string $code, string $tierKey): ?Coupon
    {
        $coupon = CouponCodes::find(Coupon::model()::query()->issuedBy(MerchantScope::platform()), $code);

        return $coupon instanceof Coupon && $coupon->isLive() && $coupon->trialDaysFor($tierKey) !== null ? $coupon : null;
    }
}
