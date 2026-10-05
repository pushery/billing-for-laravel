<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

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

    /**
     * Whether the rate has reached a threshold given as a share of payments, `0.01` being one dispute per hundred.
     *
     * Reached at the threshold itself. A window with disputes and no payment has reached every threshold, because
     * nothing in it dilutes the disputes; a window with neither has reached none. A threshold above one is refused
     * rather than never reached: written as a percentage, `1.5` would ask for three disputes per two payments.
     *
     * @throws InvalidArgumentException when the threshold is not above zero and at most one
     */
    public function reaches(float $threshold): bool
    {
        if ($threshold <= 0.0 || $threshold > 1.0) {
            throw new InvalidArgumentException("A dispute rate threshold is a share of payments above zero and at most one, such as 0.01 for one percent; {$threshold} is not.");
        }

        $ratio = $this->ratio();

        return $ratio === null ? $this->disputes > 0 : $ratio >= $threshold;
    }
}
