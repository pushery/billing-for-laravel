<?php

declare(strict_types=1);

namespace Pushery\Billing\Discounts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Pushery\Billing\Exceptions\CouponUnavailable;
use Pushery\Billing\Models\Coupon;
use Pushery\Billing\Models\CouponRedemption;

/**
 * Redeems a package-owned {@see Coupon} for an owner, enforcing every limit atomically. It is the guard
 * between "a coupon exists" and "this owner may use it now": active, not expired, under its global
 * max-redemptions cap, and not already redeemed by this owner.
 *
 * The cap check and the count increment run inside one transaction with the coupon row locked
 * (SELECT ... FOR UPDATE), so two owners racing for the last remaining redemption cannot both win — one
 * commits, the other sees the cap reached. The per-owner limit is enforced a second way, by the unique
 * (coupon, owner) index: even if two of the SAME owner's requests interleave, the database rejects the
 * second insert, so a double-apply can never grant the discount twice. (On SQLite row locking is a no-op,
 * so the concurrency guarantee is proven on PostgreSQL and MySQL; the sequential limits hold everywhere.)
 */
final readonly class CouponRedeemer
{
    /**
     * @throws CouponUnavailable when the coupon is inactive, expired, exhausted, or already redeemed here
     */
    public function redeem(Coupon $coupon, Model $owner, ?int $subscriptionId = null): CouponRedemption
    {
        return $coupon->getConnection()->transaction(function () use ($coupon, $owner, $subscriptionId): CouponRedemption {
            $locked = Coupon::query()->whereKey($coupon->getKey())->lockForUpdate()->first() ?? $coupon;

            $this->assertRedeemable($locked);

            try {
                $redemption = CouponRedemption::query()->create([
                    'owner_type' => $owner->getMorphClass(),
                    'owner_id' => $owner->getKey(),
                    'coupon_id' => $locked->getKey(),
                    'subscription_id' => $subscriptionId,
                    'redeemed_at' => Carbon::now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // The (coupon, owner) unique index fired: this owner has already redeemed the coupon.
                throw CouponUnavailable::alreadyRedeemed($locked->code);
            }

            $locked->increment('redeemed_count');

            return $redemption;
        });
    }

    private function assertRedeemable(Coupon $coupon): void
    {
        if (! $coupon->active) {
            throw CouponUnavailable::inactive($coupon->code);
        }

        if ($coupon->expires_at instanceof Carbon && $coupon->expires_at->isPast()) {
            throw CouponUnavailable::expired($coupon->code);
        }

        if ($coupon->max_redemptions !== null && $coupon->redeemed_count >= $coupon->max_redemptions) {
            throw CouponUnavailable::exhausted($coupon->code);
        }
    }
}
