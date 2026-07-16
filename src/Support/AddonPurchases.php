<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Models\AddonPurchase;
use Pushery\Billing\ValueObjects\AddonReversal;
use Pushery\Billing\ValueObjects\Money;

/**
 * Records one-time add-on purchases once per checkout reference. recordOnce returns true exactly on
 * the first delivery of a reference (apply the credit then) and false on every replay — the
 * once-per-session guarantee the webhook credit effect relies on. reverse() undoes a purchase (a
 * refund or a lost dispute) idempotently: the provider reports a CUMULATIVE refunded total, so it
 * claws back only the delta beyond what was already reversed — two partial refunds each reverse their
 * own part, and a redelivered refund reverses nothing.
 */
final class AddonPurchases
{
    public function recordOnce(Model $owner, string $reference, string $addonKey, Money $amount, ?string $paymentReference = null): bool
    {
        return AddonPurchase::query()->firstOrCreate(
            ['reference' => $reference],
            [
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'addon_key' => $addonKey,
                'amount_minor' => $amount->minorUnits,
                'currency' => $amount->currency,
                'payment_reference' => $paymentReference,
            ],
        )->wasRecentlyCreated;
    }

    /**
     * Reverse the add-on identified by its provider PAYMENT reference up to a cumulative reversed total,
     * returning the owner and the DELTA actually clawed back this time, or null when no such purchase
     * exists, its owner is gone, or nothing new is reversed. The reversed total is capped at the purchase
     * amount so an over-reported refund never claws back more than was granted, and a purchase fully
     * reversed is stamped revoked. Idempotent: a redelivered refund reverses nothing.
     */
    public function reverse(string $paymentReference, Money $reversedTotal, ?string $reason = null): ?AddonReversal
    {
        return DB::transaction(function () use ($paymentReference, $reversedTotal, $reason): ?AddonReversal {
            $purchase = AddonPurchase::query()
                ->where('payment_reference', $paymentReference)
                ->lockForUpdate()
                ->first();

            if (! $purchase instanceof AddonPurchase) {
                return null;
            }

            $cap = min($reversedTotal->minorUnits, $purchase->amount_minor);
            $delta = $cap - $purchase->reversed_minor;

            if ($delta <= 0) {
                return null; // already reversed this far (a redelivery), or nothing new
            }

            $owner = $this->ownerOf($purchase);

            if (! $owner instanceof Model) {
                return null;
            }

            $purchase->forceFill([
                'reversed_minor' => $cap,
                'revoked_at' => $cap >= $purchase->amount_minor ? Carbon::now() : $purchase->revoked_at,
                'revoked_reason' => $reason ?? $purchase->revoked_reason,
            ])->save();

            return new AddonReversal(
                $owner,
                Money::of($delta, $purchase->currency),
                $purchase->addon_key,
                $purchase->amount_minor,
            );
        });
    }

    /** Resolve the purchase's owner back to a model instance via the morph map (as UsageFlusher does). */
    private function ownerOf(AddonPurchase $purchase): ?Model
    {
        $class = Relation::getMorphedModel($purchase->owner_type) ?? $purchase->owner_type;

        if (! is_subclass_of($class, Model::class)) {
            return null;
        }

        $owner = $class::query()->find($purchase->owner_id);

        return $owner instanceof Model ? $owner : null;
    }
}
