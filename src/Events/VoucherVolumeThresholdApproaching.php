<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Carbon\CarbonImmutable;
use Pushery\Billing\ValueObjects\Money;

/**
 * Voucher volume is close to the figure at which a supervisory filing is expected.
 *
 * ## Why a warning exists at all
 *
 * A threshold you learn about on the day you cross it leaves no time to do anything about it. Registering
 * with a supervisor is paperwork with a lead time, and the operator is the only one who can start it.
 *
 * The package computes the figure and says when it is close. It files nothing, holds credentials with no
 * authority, and knows neither which regulator applies to this operator nor what form they want — a package
 * that tried would be confidently wrong about all three.
 *
 * ## What it carries, and why the figure travels with it
 *
 * The window is ROLLING, so the number that triggered this warning is not the number a recipient would
 * compute when they get round to reading it. Carrying the volume and the threshold means the message can be
 * phrased, filed and audited later against what was actually true when it was sent.
 */
final readonly class VoucherVolumeThresholdApproaching
{
    public function __construct(
        /** What has gone into vouchers over the window ending at `observedAt`. */
        public Money $volume,
        /** The figure being approached, in the same currency and minor units. */
        public Money $threshold,
        /** The end of the rolling window this was measured over — the figure is only true as of this moment. */
        public CarbonImmutable $observedAt,
    ) {}
}
