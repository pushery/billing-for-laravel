<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * A team owner's billed seat quantity was re-synced to a new value.
 *
 * Fired only when the sync actually MOVED the quantity — an in-sync re-check dispatches nothing. It carries
 * both ends so a listener can react to the delta: bill-impact analytics, a realtime seat-count refresh, an
 * audit trail of who is now paying for what. Provider-neutral, dispatched through Laravel's dispatcher, so
 * a host app can listen or `Event::fake()` it.
 */
final readonly class SeatQuantityChanged implements BillingDomainEvent
{
    public function __construct(
        public Model $owner,
        /** The quantity the provider was billing before the sync. */
        public int $from,
        /** The quantity it is billing after — the owner's current seat count. */
        public int $to,
    ) {}
}
