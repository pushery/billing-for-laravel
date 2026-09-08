<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use DateTimeInterface;
use Pushery\Billing\ValueObjects\ArrearsEntry;

/**
 * Every relationship that is behind on what it owes — the roster the reminder sweep works through.
 *
 * ## The counterpart to ArrearsClock, one axis wider
 *
 * {@see ArrearsClock} answers about ONE relationship: is this owner behind with this merchant, and since
 * when. That is what a gate needs, because a gate is always asked about somebody in particular. A SWEEP has
 * nobody in particular — it has to find them — so it needs the other direction, and until this existed it
 * got it by querying this package's `billing_subscriptions` directly.
 *
 * The consequence was that the dunning ladder was only half adoptable. An application holding its own view
 * of a subscription could bind the clock and get the suspension; the reminder INSIDE the cure window stayed
 * out of reach. That is the half that helps the person: without it the window runs out, access is gone, and
 * nobody heard anything.
 *
 * ## Reading and marking belong together
 *
 * The sweep must send exactly one message per day per relationship, and the marker for "sent today" lives
 * wherever the roster's rows live. Splitting the read from the write across two seams would leave an
 * application able to implement one and not the other, and the failure mode of that is a customer getting
 * fourteen messages in a seven-day window — which silences the sender long before the one that matters.
 */
interface ArrearsRoster
{
    /**
     * Relationships whose arrears began strictly after `$after` and that have not been reminded on `$onDay`.
     *
     * Strictly after, because a clock that started exactly on the cutoff has run out and belongs to the
     * expiry path — the two halves of one comparison, so they can never overlap into two messages on one
     * day nor leave a silent gap between them.
     *
     * @param  string  $onDay  a `Y-m-d` date; an entry already marked for it is not owed another message
     * @return iterable<ArrearsEntry>
     */
    public function inArrearsSince(DateTimeInterface $after, string $onDay): iterable;

    /** Record that this relationship was reminded on `$onDay`, so a second run the same day sends nothing. */
    public function markReminded(ArrearsEntry $entry, string $onDay): void;
}
