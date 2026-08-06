<?php

declare(strict_types=1);

namespace Pushery\Billing\Dunning;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Pushery\Billing\Events\SubscriptionExpired;
use Pushery\Billing\Models\Subscription;

/**
 * The end of the cure window: this one subscription expires, and the customer is told once.
 *
 * ## What expires, and what does not
 *
 * ONE subscription — the one in arrears. The customer's other subscriptions, to other merchants, are
 * untouched however long this one has been unpaid. That is the whole decision: access is lost to what was
 * not paid for, and to nothing else.
 *
 * ## The boundary is shared with the reminder, not merely similar to it
 *
 * The reminder sweep takes `delinquent_since > now - window`; this one takes `<=`. The two are complementary
 * halves of one comparison, so on the day a window runs out the customer receives the final message and NOT
 * a reminder as well. Writing the boundary twice with the same operator is how "exactly one message" quietly
 * becomes two, so the operators are opposite by construction rather than by care.
 *
 * ## A period already paid for is not clawed back
 *
 * Arrears concern the period that was NOT paid. If the last paid period still runs, access continues to its
 * end — the subscription is canceled now, and lapses then. The package already has exactly this shape:
 * `ends_at` in the future is "canceled but paid through the period end", which `onGracePeriod()` reads. So
 * expiry sets `ends_at`, and the ordinary case (nothing left paid for) simply sets it to now.
 *
 * The obvious implementation — canceled means access off — gets this wrong, which is why it has its own
 * test rather than a comment.
 *
 * ## Terminal, and terminal has to be written down
 *
 * `status = ended` is not enough on its own: every webhook overwrites status, so a provider reporting the
 * subscription active again would hand the access back. `terminated_at` records that a decision was taken,
 * and the sync path refuses to lift a row out of it for the same provider subscription.
 *
 * ## Merchant rows only
 *
 * Like the reminder next door. The cure window is a marketplace mechanism; a single-seller install runs the
 * dunning ladder, which withdraws surfaces and never cancels. Selecting on the row rather than on the
 * marketplace flag keeps mode S byte-identical whatever the flag does.
 */
final readonly class SubscriptionExpirySweep
{
    public function __construct(
        private CureWindow $window,
        private Dispatcher $events,
    ) {}

    /**
     * Expire every subscription whose cure window has run out.
     *
     * @return int how many subscriptions expired
     */
    public function expire(CarbonImmutable $now): int
    {
        $moment = Carbon::instance($now);

        // The complement of the reminder's `>`, from the same object: a clock that started exactly `window`
        // days ago has run out.
        $cutoff = $this->window->cutoff($now);

        $due = Subscription::query()
            ->merchantScoped()
            ->whereNotNull('delinquent_since')
            ->where('delinquent_since', '<=', $cutoff)
            // Idempotence, and it is a real guard rather than a formality: the dunning clock is cleared
            // below, so a second run would not re-select these rows anyway — but a row whose clock is
            // restarted by a later failed payment must not be expired twice on the strength of the first.
            ->whereNull('terminated_at')
            ->orderBy('id')
            ->get();

        $expired = 0;

        foreach ($due as $subscription) {
            $accessEndsAt = $this->accessEndsAt($subscription, $moment);

            // Dispatch first, then write — the same order the reminder uses, and for the same reason: a
            // crash between the two re-sends the final message, which a recipient survives, where the other
            // order would expire a subscription in silence and leave the customer to discover it.
            $this->events->dispatch(new SubscriptionExpired($subscription, $accessEndsAt));

            $subscription->forceFill([
                'status' => 'ended',
                'ends_at' => $accessEndsAt,
                'terminated_at' => $moment,
                // Dunning is over: its outcome is now recorded in the row itself. Leaving the clock running
                // would keep both sweeps and the ladder selecting a subscription that no longer exists.
                'delinquent_since' => null,
                'dunning_level' => 0,
                'payment_reminded_on' => null,
            ])->save();

            $expired++;
        }

        return $expired;
    }

    /**
     * When access actually stops: at the end of a period already paid for, or now when none is running.
     *
     * `current_period_end` is the package's only record of the period boundary, and the local engine
     * advances it on a successful charge — so a value in the future is a period the customer has paid for.
     * A driver that advanced it on invoice CREATION instead would be claiming a period nobody paid for, and
     * would grant a remainder it should not; that is a property of the driver, and it is the reason this
     * reads one column rather than inferring the boundary from amounts.
     */
    private function accessEndsAt(Subscription $subscription, Carbon $now): Carbon
    {
        $periodEnd = $subscription->current_period_end;

        return $periodEnd instanceof Carbon && $periodEnd->greaterThan($now)
            ? $periodEnd
            : $now;
    }
}
