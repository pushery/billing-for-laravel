<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * What a cancellation to a date comes to: when the subscription ends, and what goes back of what was paid.
 *
 * The end is stated rather than assumed, because it is not always the moment asked for. A moment at or after the
 * end of the period in progress ends the subscription at the period end, where nothing is owed back, and the
 * confirmation a customer receives has to name the moment that actually applies.
 *
 * ## The figures, or none
 *
 * `paid` is null when no payment covers the period in progress. That is the ordinary case on a driver that collects
 * a period at its end: nothing was paid for this period, so nothing goes back. With a payment, `refundable` is what
 * goes back and never null, zero when the subscription ends at the period end. What is kept is the difference, never
 * its own rounding, so the two always add up to the payment.
 */
final readonly class CancellationSettlement
{
    public function __construct(
        /** When the subscription ends: the moment asked for, or the period end when that comes first. */
        public CarbonImmutable $endsAt,
        /** What was paid for the period in progress, gross, or null when no payment covers it. */
        public ?Money $paid = null,
        /** What goes back: the part of the payment after the end. Null exactly when `paid` is. */
        public ?Money $refundable = null,
        /**
         * Whether the provider refused to return what goes back.
         *
         * The figures above still state what is owed, and it is still owed. A caller that keeps a cancellation
         * open until the money has moved reads that here. A settlement that was only quoted, or that had nothing
         * to return, had nothing refused.
         */
        public bool $refundRefused = false,
        /** The payment the refund went against, so a second attempt can name it. Null when nothing was refunded. */
        public ?string $chargeReference = null,
    ) {}

    /** What is kept for the part of the period before the end, or null when no payment covers the period. */
    public function retained(): ?Money
    {
        if (! $this->paid instanceof Money || ! $this->refundable instanceof Money) {
            return null;
        }

        return $this->paid->minus($this->refundable);
    }

    /** Whether any money goes back. An end at the period end owes nothing and is not a refund. */
    public function movesMoney(): bool
    {
        return $this->refundable instanceof Money && $this->refundable->minorUnits > 0;
    }
}
