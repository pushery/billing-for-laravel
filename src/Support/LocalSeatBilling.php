<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\ProvidesSeats;
use Pushery\Billing\Contracts\SeatBilling;
use Pushery\Billing\Exceptions\SeatDowngradeBelowOccupied;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Proration\RestOfPeriod;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\Plan;

/**
 * Seat billing for a subscription the local engine bills: the quantity lives on the subscription row.
 *
 * A provider that bills seats keeps the quantity on its tier item and prorates a change itself. Here the engine
 * does, in one of two ways. Collected in arrears, a period is billed when it closes, so a change is recorded with
 * the moment it takes effect and the cycle bills each quantity for the days it held
 * ({@see Subscription::changeSeatQuantity()}). Collected in advance, the period was paid at the old quantity when it
 * began, so a change squares the rest of it at once: added seats are charged for the time left, removed ones leave
 * credit ({@see RestOfPeriod}). Without proration the new quantity starts with the next period either way, and the
 * running one stays at the old quantity throughout.
 *
 * It reads the owner's own subscription of the default type that this engine bills, the one the seat service
 * keeps in step. A subscription that has never had a seat change bills one seat, and reads as one. One that has
 * ended bills nothing and reads as none, so a membership change after it ended changes nothing on it.
 */
final readonly class LocalSeatBilling implements SeatBilling
{
    public function __construct(
        private string $provider,
        /**
         * Squares a change inside a period collected in advance. Optional, so a driver that builds this with its
         * name alone keeps working; there, a change inside such a period is recorded as in arrears and charged
         * with nothing until the next period.
         */
        private ?RestOfPeriod $rest = null,
        /** Prices the tier the seats are of, for the squaring above. Optional for the same reason. */
        private ?PlanCatalog $plans = null,
    ) {}

    public function currentSeatQuantity(Model $owner): ?int
    {
        $subscription = $this->subscriptionOf($owner);

        if (! $subscription instanceof Subscription) {
            return null;
        }

        return $subscription->seat_quantity ?? 1;
    }

    public function updateSeatQuantity(Model $owner, int $quantity, bool $prorate = true): void
    {
        // The floor first, as on every driver: billing below what is occupied is a silent oversell.
        if ($owner instanceof ProvidesSeats && $quantity < $owner->occupiedSeatCount()) {
            throw SeatDowngradeBelowOccupied::for($quantity, $owner->occupiedSeatCount());
        }

        $subscription = $this->subscriptionOf($owner);

        if (! $subscription instanceof Subscription) {
            return;
        }

        $from = Carbon::now();

        if (! $prorate && $subscription->current_period_end instanceof Carbon) {
            $from = $subscription->current_period_end;
        }

        if ($prorate && $subscription->collectsInAdvance()) {
            $this->squareTheRestOfThePeriod($owner, $subscription, $quantity, $from);
        }

        $subscription->changeSeatQuantity($quantity, $from);
    }

    /** Charge the added seats for the rest of a paid period, or credit the removed ones. */
    private function squareTheRestOfThePeriod(Model $owner, Subscription $subscription, int $quantity, Carbon $at): void
    {
        $current = $subscription->seat_quantity ?? 1;
        $plan = $subscription->tier_key === null ? null : $this->plans?->planFor($subscription->tier_key);

        if (! $this->rest instanceof RestOfPeriod || ! $plan instanceof Plan || $quantity === $current) {
            return;
        }

        $difference = $this->rest->forSeatChange($subscription, $plan, $current, $quantity, $at);

        if ($difference instanceof Money && ! $difference->isZero()) {
            $this->rest->square($owner, $subscription, $difference, "{$plan->key}: {$current} to {$quantity} seats for the rest of the period", $at);
        }
    }

    private function subscriptionOf(Model $owner): ?Subscription
    {
        $subscription = Subscription::model()::query()
            ->forOwner($owner)
            ->ofDefaultType()
            ->forMerchant(null)
            ->where('provider', $this->provider)
            ->latest('id')
            ->first();

        // The two terminal states, the ones a new subscription may replace. A seat change written onto such a row
        // would count seat-days from the start of a period its last cycle already billed.
        return $subscription instanceof Subscription && ! $subscription->isReplaceableByANewSubscription() ? $subscription : null;
    }
}
