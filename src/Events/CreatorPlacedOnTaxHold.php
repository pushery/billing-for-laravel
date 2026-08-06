<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * A merchant can no longer sell or be paid out, because their tax standing does not permit it.
 *
 * ## Why this event was once removed, and what had to change before it came back
 *
 * It shipped as documented API and **no code path ever dispatched it**. Anyone who listened waited forever —
 * no error, no red test, no clue. It was deleted rather than quietly attached to the one path that was easy
 * to wire, and that deletion was the right call: a merchant reaches a hold by TWO routes, and an event that
 * fires for one while staying silent for the other is worse than none. Its silence would read as "no hold".
 *
 * The two routes:
 *
 * 1. **Somebody records a blocking standing.** Observable already — a write happens, and a write can be
 *    watched.
 * 2. **An attestation expires.** Nothing is written. `statusAt()` simply begins answering `Unclarified`
 *    because time passed. No event, no row, no signal — and this is the route where the merchant most needs
 *    telling, because they did nothing and can suddenly neither sell nor be paid.
 *
 * So it returns wired to **both**, which is the condition under which a listener can trust it.
 *
 * ## The reason is a key, not a sentence
 *
 * `reasonKey` is translated at the edge. Why a standing blocks is a jurisdiction's rule; the person reading
 * it may sit in another one, and a German sentence baked into an event reaches them untranslated.
 */
final readonly class CreatorPlacedOnTaxHold implements BillingDomainEvent
{
    public function __construct(
        public Model $merchant,
        /** A translation key naming why the hold began — never a rendered sentence. */
        public string $reasonKey,
    ) {}
}
