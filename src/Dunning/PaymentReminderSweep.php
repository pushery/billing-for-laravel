<?php

declare(strict_types=1);

namespace Pushery\Billing\Dunning;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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
 * ## Merchant rows only, and that is not the same as reading the marketplace flag
 *
 * The cure window is a marketplace mechanism: it exists because a customer holds several subscriptions to
 * several merchants and loses only the ones in arrears. A single-seller install keeps the dunning ladder,
 * where nothing expires after a week — so a reminder counting down "5 days left" there would name a deadline
 * that never arrives, which is worse than silence.
 *
 * The sweep therefore selects rows that came through the marketplace, not installs that have the flag on.
 * The narrower test is the right one: it stays byte-identical in a single-seller install whatever the flag
 * does, and it keeps an install that switches the flag on from retroactively pulling its own platform
 * subscriptions into a rule they were never sold under.
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

        $due = Subscription::query()
            ->merchantScoped()
            ->whereNotNull('delinquent_since')
            ->where('delinquent_since', '>', $cutoff)
            ->where(function (Builder $query) use ($today): void {
                $query->whereNull('payment_reminded_on')
                    ->orWhereDate('payment_reminded_on', '<', $today);
            })
            ->orderBy('id')
            ->get();

        $sent = 0;

        foreach ($due as $subscription) {
            // The query already filtered on a non-null clock, so the coalesce is a type narrowing rather
            // than a fallback — and written as one statement rather than a guard, because a branch that
            // cannot be reached is a branch no test can cover honestly.
            $since = $subscription->delinquent_since ?? $now;

            $this->events->dispatch(new PaymentReminderDue($subscription, $this->daysLeft($since, $now, $window)));

            $subscription->forceFill(['payment_reminded_on' => $today])->save();

            $sent++;
        }

        return $sent;
    }

    /** How many whole days of the cure window remain, never below zero. */
    private function daysLeft(CarbonInterface $since, CarbonImmutable $now, int $window): int
    {
        $elapsed = (int) Carbon::instance($since)->startOfDay()->diffInDays(Carbon::instance($now)->startOfDay());

        return max(0, $window - $elapsed);
    }
}
