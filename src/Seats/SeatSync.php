<?php

declare(strict_types=1);

namespace Pushery\Billing\Seats;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Pushery\Billing\Contracts\ProvidesSeats;
use Pushery\Billing\Contracts\SeatBilling;
use Pushery\Billing\Events\SeatQuantityChanged;

/**
 * Keeps the seat quantity a team is billed for in step with the seats it actually occupies.
 *
 * This is provider-neutral on purpose: it decides WHETHER a change is needed and delegates the actual
 * provider call to {@see SeatBilling}. That split is what lets one service serve every driver, and it is
 * why a membership change — a member joining, leaving, being removed — can safely re-sync seats without the
 * caller knowing anything about the billing provider.
 *
 * It does nothing unless it must: not a seat owner, no seat billing at the provider, or already in sync — all
 * no-ops. Only a genuine drift reaches the provider, and only then is {@see SeatQuantityChanged} fired. That
 * matters because this runs on every membership change: an owner whose seat count did not move must not pay
 * the cost of a needless provider write, nor have an event claim a change that did not happen.
 */
final readonly class SeatSync
{
    /** How often one sync writes before it leaves a still-moving count to the sync of the next change. */
    private const int ROUNDS = 3;

    public function __construct(private SeatBilling $billing) {}

    /**
     * Membership changes sync from queued jobs, and two of them for one team can overlap: the job that counted the
     * seats first can write after the job that counted them later, and leave the older count billed. So a sync counts
     * the seats again after it wrote, and writes again while the count moved under it. The job whose older count
     * landed last is the one that sees the newer count afterwards.
     */
    public function sync(Model $owner): void
    {
        if (! $owner instanceof ProvidesSeats) {
            return; // a personal owner, or any model that does not bill by seats
        }

        $current = $this->billing->currentSeatQuantity($owner);

        if ($current === null) {
            return; // no active seat subscription to keep in sync — nothing to correct
        }

        $target = $owner->seatCount();

        for ($round = 0; $round < self::ROUNDS && $current !== $target; $round++) {
            $this->billing->updateSeatQuantity($owner, $target);

            Event::dispatch(new SeatQuantityChanged($owner, $current, $target));

            $current = $target;
            $target = $owner->seatCount();
        }
    }
}
