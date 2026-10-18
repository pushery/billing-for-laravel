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
use Pushery\Billing\ValueObjects\UnitGrant;

/**
 * Records one-time add-on purchases once per checkout reference. recordOnce returns true exactly on
 * the first delivery of a reference (apply the credit then) and false on every replay — the
 * once-per-session guarantee the webhook credit effect relies on. reverse() undoes a purchase (a
 * refund or a lost dispute) idempotently: the provider reports a cumulative refunded total, so it
 * claws back only the delta beyond what was already reversed — two partial refunds each reverse their
 * own part, and a redelivered refund reverses nothing.
 */
final class AddonPurchases
{
    /**
     * @param  ?string  $declarationReference  the key the buyer's pre-purchase declarations were recorded
     *                                         against, minted before the redirect and echoed back by the
     *                                         provider. Kept on the row because the receipt knows the
     *                                         PAYMENT id and the declarations are not keyed on it -- without
     *                                         it that lookup answers null, and null there reads as "the
     *                                         buyer declared nothing"
     * @param  ?bool  $moneyCredit  whether this purchase puts money on the buyer's credit balance, kept so the
     *                              refund and the prepaid volume read what the purchase did rather than what the
     *                              catalog says about the key at the time they ask
     * @param  ?UnitGrant  $granted  the usage units this purchase grants, kept so a refund takes back a share of
     *                               them rather than of whatever the catalog grants for the key by then
     */
    public function recordOnce(Model $owner, string $reference, string $addonKey, Money $amount, ?string $paymentReference = null, ?string $declarationReference = null, ?bool $moneyCredit = null, ?UnitGrant $granted = null): bool
    {
        return UniqueRow::firstOrCreate(
            AddonPurchase::model()::query(),
            ['reference' => $reference],
            [
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'addon_key' => $addonKey,
                'amount_minor' => $amount->minorUnits,
                'currency' => $amount->currency,
                'payment_reference' => $paymentReference,
                'declaration_reference' => $declarationReference,
                'money_credit' => $moneyCredit,
                'granted_meter_key' => $granted?->meterKey,
                'granted_units' => $granted?->units,
            ],
        )->wasRecentlyCreated;
    }

    /**
     * Reverse the add-on identified by its provider payment reference up to a cumulative reversed total,
     * returning the owner and the delta actually clawed back this time, or null when no such purchase
     * exists, its owner is gone, or nothing new is reversed. The reversed total is capped at the purchase
     * amount so an over-reported refund never claws back more than was granted, and a purchase fully
     * reversed is stamped revoked. Idempotent: a redelivered refund reverses nothing.
     */
    public function reverse(string $paymentReference, Money $reversedTotal, ?string $reason = null): ?AddonReversal
    {
        return DB::transaction(function () use ($paymentReference, $reversedTotal, $reason): ?AddonReversal {
            $purchase = AddonPurchase::model()::query()
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
                $purchase->money_credit,
                $purchase->granted_meter_key !== null && $purchase->granted_units !== null ? new UnitGrant($purchase->granted_meter_key, $purchase->granted_units) : null,
            );
        });
    }

    /**
     * Record the usage units a reversal of this purchase clawed back, so a restoration gives back exactly those.
     *
     * A clawback takes only what the owner has not used, so a share of the grant would give back units they never lost.
     */
    public function recordClawedBack(string $paymentReference, int $units): void
    {
        if ($units <= 0) {
            return;
        }

        AddonPurchase::model()::query()
            ->where('payment_reference', $paymentReference)
            ->increment('clawed_back_units', $units);
    }

    /**
     * Give back the part of a reversal that no longer stands, returning the owner and the amount given back, or null
     * when no such purchase exists, its owner is gone, or nothing more is reversed than still stands.
     *
     * The way back of reverse(). A chargeback the bank took back leaves the payment with less refunded or charged back,
     * and the purchase follows it down to that total and never below it, so a refund that still stands stays reversed.
     * The units a clawback took come back in the share of the reversal that is given back, and a purchase no longer
     * reversed in full is no longer revoked. Idempotent: a second delivery finds the purchase at the standing total.
     */
    public function restore(string $paymentReference, Money $standingReversed): ?AddonReversal
    {
        return DB::transaction(function () use ($paymentReference, $standingReversed): ?AddonReversal {
            $purchase = AddonPurchase::model()::query()
                ->where('payment_reference', $paymentReference)
                ->lockForUpdate()
                ->first();

            if (! $purchase instanceof AddonPurchase || $purchase->currency !== $standingReversed->currency) {
                return null;
            }

            $standing = max(0, min($standingReversed->minorUnits, $purchase->amount_minor));
            $delta = $purchase->reversed_minor - $standing;

            if ($delta <= 0) {
                return null; // nothing more reversed than still stands: a redelivery, or a purchase never reversed
            }

            $owner = $this->ownerOf($purchase);

            if (! $owner instanceof Model) {
                return null;
            }

            $units = intdiv($purchase->clawed_back_units * $delta, $purchase->reversed_minor);

            $purchase->forceFill([
                'reversed_minor' => $standing,
                'clawed_back_units' => $purchase->clawed_back_units - $units,
                'revoked_at' => $standing >= $purchase->amount_minor ? $purchase->revoked_at : null,
            ])->save();

            return new AddonReversal(
                $owner,
                Money::of($delta, $purchase->currency),
                $purchase->addon_key,
                $purchase->amount_minor,
                $purchase->money_credit,
                $purchase->granted_meter_key !== null && $purchase->granted_units !== null ? new UnitGrant($purchase->granted_meter_key, $purchase->granted_units) : null,
                $units,
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
