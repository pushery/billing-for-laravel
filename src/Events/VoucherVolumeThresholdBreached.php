<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Carbon\CarbonImmutable;
use Pushery\Billing\ValueObjects\Money;

/**
 * Voucher volume has passed the figure at which a supervisory filing is expected.
 *
 * ## The failure this exists to end
 *
 * The figure was computed correctly and told to nobody. An operator found out that a threshold had been
 * passed only by asking, and nothing in the package suggested they should ask — so the ordinary way to learn
 * it was a letter, months later, about a notification that was due eleven months ago.
 *
 * ## It announces; nothing here files
 *
 * Crossing a threshold is a fact about a number. What follows from it is a filing a person makes, to an
 * authority this package cannot identify, on a form it does not have. Announcing is the whole of what a
 * package can honestly do, and doing it late is the same as not doing it.
 *
 * The sibling {@see VoucherVolumeThresholdApproaching} is deliberately a separate event rather than a level
 * on this one: a recipient that treated them as one message would let the early warning stand for the late
 * one, and the late one is the one that has a deadline attached.
 */
final readonly class VoucherVolumeThresholdBreached
{
    public function __construct(
        /** What has gone into vouchers over the window ending at `observedAt`. */
        public Money $volume,
        /** The figure that was passed, in the same currency and minor units. */
        public Money $threshold,
        /** The end of the rolling window this was measured over — the figure is only true as of this moment. */
        public CarbonImmutable $observedAt,
    ) {}
}
