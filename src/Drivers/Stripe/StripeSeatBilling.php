<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Database\Eloquent\Model;
use Psr\Log\LoggerInterface;
use Pushery\Billing\Contracts\ProvidesSeats;
use Pushery\Billing\Contracts\SeatBilling;
use Pushery\Billing\Exceptions\SeatDowngradeBelowOccupied;
use Pushery\Billing\Models\Subscription;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;
use Stripe\SubscriptionItem;

/**
 * Stripe's seat billing: reads and writes the quantity on the subscription's TIER item.
 *
 * The quantity always goes on the base tier item, resolved by price rather than position — a subscription
 * that also carries a metered component would reject a quantity on the meter, and writing it onto the wrong
 * item bills the team for the wrong thing. Proration is Stripe's own: it books the credit/charge for a
 * mid-cycle change on its side, so the driver only chooses whether to ask for it (`create_prorations`) or
 * suppress it (`none`).
 *
 * A permanent rejection (Stripe's InvalidRequestException — a bad price, a metered item) is logged, never
 * retried: retrying a doomed request storms the queue. A transient failure (a dropped connection, a
 * rate-limit) is allowed to propagate so the queued caller retries it. And a downgrade below the occupied
 * seat count fails LOUDLY — that one is a policy error, not a provider hiccup, and swallowing it would
 * silently under-bill the team.
 */
final readonly class StripeSeatBilling implements SeatBilling
{
    public function __construct(
        private StripeClient $stripe,
        private StripeSubscriptionItems $items,
        private LoggerInterface $log,
    ) {}

    public function currentSeatQuantity(Model $owner): ?int
    {
        $reference = $this->subscriptionReference($owner);

        if ($reference === null) {
            return null;
        }

        try {
            $subscription = $this->stripe->subscriptions->retrieve($reference);
        } catch (InvalidRequestException) {
            return null; // the subscription is gone or already canceled
        }

        $base = $this->items->base($subscription);

        if (! $base instanceof SubscriptionItem) {
            return null; // no identifiable tier item — nothing whose quantity is the seat count
        }

        $quantity = $base->quantity ?? null;

        return is_int($quantity) ? $quantity : null;
    }

    public function updateSeatQuantity(Model $owner, int $quantity, bool $prorate = true): void
    {
        // The floor: never bill below what is occupied. This is a policy violation, not a provider error,
        // so it throws rather than logs — a silent under-bill is exactly what must not happen quietly.
        if ($owner instanceof ProvidesSeats && $quantity < $owner->occupiedSeatCount()) {
            throw SeatDowngradeBelowOccupied::for($quantity, $owner->occupiedSeatCount());
        }

        $reference = $this->subscriptionReference($owner);

        if ($reference === null) {
            return; // no subscription to update
        }

        try {
            $subscription = $this->stripe->subscriptions->retrieve($reference);
        } catch (InvalidRequestException) {
            return; // gone or canceled — nothing to update
        }

        $base = $this->items->base($subscription);

        if (! $base instanceof SubscriptionItem) {
            $this->log->warning('Seat quantity update skipped: the tier item on the subscription cannot be identified.', [
                'subscription' => $reference,
            ]);

            return;
        }

        try {
            $this->stripe->subscriptions->update($reference, [
                'items' => [['id' => $base->id, 'quantity' => $quantity]],
                'proration_behavior' => $prorate ? 'create_prorations' : 'none',
            ]);
        } catch (InvalidRequestException $e) {
            // Permanent (a 400): log and stop, rather than retry a request that can never succeed.
            $this->log->warning('Seat quantity update failed against Stripe.', [
                'subscription' => $reference,
                'quantity' => $quantity,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /** The provider subscription reference from the owner's local subscription row, or null. */
    private function subscriptionReference(Model $owner): ?string
    {
        $subscription = Subscription::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('type', 'default')
            ->latest('id')
            ->first();

        return $subscription?->provider_id;
    }
}
