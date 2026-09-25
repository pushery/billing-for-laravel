<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\ProvidesSeats;
use Pushery\Billing\Contracts\SeatBilling;
use Pushery\Billing\Exceptions\SeatDowngradeBelowOccupied;
use Pushery\Billing\Models\Subscription;

/**
 * Seat billing for a subscription the local engine bills: the quantity lives on the subscription row.
 *
 * A provider that bills seats keeps the quantity on its tier item and prorates a change itself. Here the engine
 * bills a period when it closes, so a change is recorded with the moment it takes effect and the cycle bills
 * each quantity for the days it held ({@see Subscription::changeSeatQuantity()}). Without proration the new
 * quantity starts with the next period and the running one is billed at the old quantity throughout.
 *
 * It reads the owner's own subscription of the default type that this engine bills, the one the seat service
 * keeps in step. A subscription that has never had a seat change bills one seat, and reads as one.
 */
final readonly class LocalSeatBilling implements SeatBilling
{
    public function __construct(private string $provider) {}

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

        $subscription->changeSeatQuantity($quantity, $from);
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

        return $subscription instanceof Subscription ? $subscription : null;
    }
}
