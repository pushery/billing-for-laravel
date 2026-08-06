<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One billed period of a subscription, with the share of the price that belongs to it.
 *
 * The end is INCLUSIVE — the last day covered, not the first day of the next period. A period stated as
 * "1 March to 1 April" claims a day the next one also claims, and a document that says so is wrong in a way
 * that only shows when somebody lines two of them up.
 */
final readonly class ServicePeriod
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public Money $amount,
    ) {
        if ($to->lessThan($from)) {
            throw new InvalidArgumentException('A service period ends after it begins.');
        }
    }

    /** The period as a document states it: two dates, both inclusive. */
    public function startsOn(): string
    {
        return $this->from->toDateString();
    }

    public function endsOn(): string
    {
        return $this->to->toDateString();
    }

    /** Whether this period begins the day after another ends — the only way two periods may meet. */
    public function followsDirectly(self $earlier): bool
    {
        return $this->from->toDateString() === $earlier->to->addDay()->toDateString();
    }

    /** A stable key for the period, which is what makes a second billing run recognizable as a repeat. */
    public function key(): string
    {
        return $this->startsOn().'/'.$this->endsOn();
    }
}
