<?php

declare(strict_types=1);

namespace Pushery\Billing\Dunning;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\ArrearsRoster;
use Pushery\Billing\Events\PaymentReminderDue;
use Pushery\Billing\Models\Subscription;

/**
 * The daily reminder inside the cure window: one message per day, per subscription in arrears.
 *
 * ## The window this belongs to
 *
 * Arrears withdraw the merchant's own surfaces IMMEDIATELY — the customer has already lost access when this
 * sweep first speaks. The window that follows is a chance to cure, not a grace period with the service still
 * running, and the reminder has to read that way: it is not "your payment is outstanding", it is "this is
 * still recoverable, and here is what it costs when it is not".
 *
 * ## One per day, and why the marker sits where it does
 *
 * Two runs on the same day must produce one message. Schedulers overlap, a partial failure gets retried by
 * hand, and a customer who receives fourteen messages in a seven-day window has silenced the sender long
 * before the one that matters arrives.
 *
 * The marker is a DATE on the subscription row. Not a boolean, because this repeats and a flag would
 * silence day two onward. And not on the customer, because arrears are per relationship: a marker on the
 * customer would let the reminder for one merchant suppress another merchant's reminder the same day. That
 * is the platform-wide-lockout defect one level down, and harder to see — the customer does get a message,
 * and only the second one is missing.
 *
 * ## Where the rows come from
 *
 * From an {@see ArrearsRoster}, not from a query here. The ladder's other half — the suspension — already
 * reads its clock through a seam, and an application holding its own view of a subscription could therefore
 * adopt the lockout and not the reminder. That is the half that helps the person: without it the window
 * runs out, access is gone, and nobody heard anything.
 *
 * {@see LocalArrearsRoster} is the shipped one and carries the query this class used to run inline,
 * including the reason it selects merchant-scoped rows rather than reading the marketplace flag.
 *
 * ## The order of the two writes is deliberate
 *
 * Dispatch first, then mark. A crash between them re-sends once, which a recipient survives; the other
 * order loses the reminder entirely on a day that is one of only seven. The tax-hold sweep next door
 * settled this the same way and for the same reason.
 */
final readonly class PaymentReminderSweep
{
    public function __construct(
        private CureWindow $window,
        private Dispatcher $events,
        private ArrearsRoster $roster,
    ) {}

    /**
     * Remind every subscription that is in arrears and still inside its cure window.
     *
     * @return int how many reminders were sent
     */
    public function remind(CarbonImmutable $now): int
    {
        $window = $this->window->days();
        $today = Carbon::instance($now)->toDateString();

        // Strictly AFTER the cutoff: a subscription whose clock started exactly `window` days ago has run out
        // and belongs to the expiry path, not to this one. The expiry takes the other half of this same
        // comparison, from the same object, so the two can never overlap into two messages on one day nor
        // leave a silent gap between them.
        $cutoff = $this->window->cutoff($now);

        $sent = 0;

        foreach ($this->roster->inArrearsSince($cutoff, $today) as $entry) {
            $this->events->dispatch(new PaymentReminderDue(
                $entry,
                $this->daysLeft($entry->since, $now, $window),
                $entry->subscription instanceof Subscription ? $entry->subscription : null,
            ));

            $this->roster->markReminded($entry, $today);

            $sent++;
        }

        return $sent;
    }

    /**
     * How many whole days of the cure window remain, never below zero.
     *
     * Takes the interface the seam speaks in rather than Carbon's own: an application's roster hands over
     * whatever date type its storage produces, and narrowing here would push a conversion onto every
     * implementor for no gain — `Carbon::instance()` below accepts any of them.
     */
    private function daysLeft(DateTimeInterface $since, CarbonImmutable $now, int $window): int
    {
        $elapsed = (int) Carbon::instance($since)->startOfDay()->diffInDays(Carbon::instance($now)->startOfDay());

        return max(0, $window - $elapsed);
    }
}
