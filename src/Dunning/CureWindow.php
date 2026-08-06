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
     * Floors at one. A window of zero would put the reminder and the expiry on the same day, so the customer
     * would be told they could still fix it and lose the subscription in the same breath — a formality, not
     * a chance. The default is the owner's decision rather than a round number: one week.
     */
    public function days(): int
    {
        $configured = $this->config->get('billing.dunning_cure_window_days');

        return is_int($configured) && $configured >= 1 ? $configured : 7;
    }

    /**
     * The instant that separates "still inside the window" from "run out".
     *
     * A clock that started strictly after this is inside; one that started exactly on it, or earlier, has
     * run out. The boundary belongs to the expiry — sending "you can still fix this" on the day it stops
     * being true is worse than sending nothing.
     */
    public function cutoff(CarbonImmutable $now): Carbon
    {
        return Carbon::instance($now)->subDays($this->days());
    }
}
