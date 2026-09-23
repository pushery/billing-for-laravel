<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * How many of the payments in a window were disputed, with both counts named.
 *
 * The counts are the result and the ratio is derived from them, not the other way round. Two readers who
 * compare a ratio cannot tell whether they agree on what was counted; two who compare the counts can.
 */
final readonly class DisputeRate
{
    public function __construct(
        /** Disputes opened in the window, whatever their outcome. */
        public int $disputes,
        /** Payments that arrived in the window. */
        public int $payments,
        /** The start of the window, inclusive. */
        public CarbonImmutable $from,
        /** The end of the window, exclusive. */
        public CarbonImmutable $to,
    ) {}

    /**
     * Disputes per payment, or null where there was no payment to measure against.
     *
     * Null rather than zero: a window without payments has no rate, and a zero would read as a clean record.
     */
    public function ratio(): ?float
    {
        return $this->payments === 0 ? null : $this->disputes / $this->payments;
    }
}
