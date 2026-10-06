<?php

declare(strict_types=1);

namespace Pushery\Billing\Dunning;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;

/**
 * How long a subscription in arrears may still be rescued — one reading, for both sweeps that need it.
 *
 * ## Why this is its own object
 *
 * The reminder speaks INSIDE the window and the expiry acts at its END, so the two select complementary
 * halves of one comparison: `delinquent_since > cutoff` and `delinquent_since <= cutoff`. That only holds if
 * both compute the same cutoff. Spelled twice, the halves drift apart the first time the floor or the
 * subtraction is touched — and the drift is invisible in the direction that matters, because a gap sends
 * nothing and an overlap sends a reminder and a final notice on the same day. One is silent, the other is
 * the exact defect the "exactly one message" rule exists to prevent.
 *
 * So the boundary is computed here, once, and each sweep applies its own operator to it.
 */
final readonly class CureWindow
{
    public function __construct(private Repository $config) {}

    /**
     * The window in days.
     *
     * Floors at one: a smaller number is raised to one day. A window of zero would put the reminder and the
     * expiry on the same day, so the customer would be told they could still fix it and lose the subscription
     * in the same breath — a formality, not a chance. The default, for a value that is not a number at all, is
     * the owner's decision rather than a round number: one week.
     */
    public function days(): int
    {
        $configured = $this->config->get('billing.dunning_cure_window_days');

        return is_numeric($configured) ? max(1, (int) $configured) : 7;
    }

    /**
     * The instant that separates "still inside the window" from "run out": the end of the day `days()` back.
     *
     * A clock that started strictly after this is inside; one that started on it, or earlier, has run out. The
     * boundary belongs to the expiry — sending "you can still fix this" on the day it stops being true is worse
     * than sending nothing.
     *
     * A calendar day in the zone of `$now`, not an instant `days()` earlier, because the two sweeps run at
     * different times of the day. Counted from the moment each of them runs, the halves would overlap by the time
     * between the runs, and a clock that started in that stretch would be reminded with no day left and expired on
     * the same day. Counted in days, every run on one day reads the same boundary, and a window that started on a
     * given day ends `days()` days later, whatever time the payment failed.
     */
    public function cutoff(CarbonImmutable $now): Carbon
    {
        return Carbon::instance($now)->subDays($this->days())->endOfDay();
    }
}
