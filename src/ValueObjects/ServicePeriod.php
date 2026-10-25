<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One billed period of a subscription, with the share of the price that belongs to it.
 *
 * The end is inclusive — the last day covered, not the first day of the next period. A period stated as
 * "1 March to 1 April" claims a day the next one also claims, and a document that says so is wrong in a way
 * that only shows when somebody lines two of them up.
 *
 * A buyer who holds two subscriptions over the same stretch, with two merchants or as two contract types, has two
 * periods there, and the subject tells them apart: the subscription the period is billed for. It is part of the
 * key, so each period gets its own document. A buyer who holds one subscription needs none, and the key is then the
 * two dates, as it always was.
 */
final readonly class ServicePeriod
{
    /** The longest subject the stored key can carry: it holds 255 characters, and the dates and the `@` take 22. */
    private const int SUBJECT_LENGTH = 233;

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public Money $amount,
        /** What the period is billed for, the subscription's own reference, or null for a buyer who holds one. */
        public ?string $subject = null,
    ) {
        if ($to->lessThan($from)) {
            throw new InvalidArgumentException('A service period ends after it begins.');
        }

        if ($subject !== null && ($subject === '' || strlen($subject) > self::SUBJECT_LENGTH)) {
            throw new InvalidArgumentException("A service period names what it is billed for in 1 to 233 bytes; got '{$subject}'.");
        }
    }

    /** The same period, billed for the given subject: the way to give a whole schedule its subscription. */
    public function withSubject(string $subject): self
    {
        return new self($this->from, $this->to, $this->amount, $subject);
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

    /**
     * A stable key for the period, which is what makes a second billing run recognizable as a repeat.
     *
     * The subject, where there is one, follows the dates after an `@`, as in `2026-01-01/2026-01-31@subscription:12`.
     */
    public function key(): string
    {
        $dates = $this->startsOn().'/'.$this->endsOn();

        return $this->subject === null ? $dates : $dates.'@'.$this->subject;
    }

    /**
     * The subject a stored key names, or null where it names none.
     *
     * The dates hold no `@`, so the first one is where the subject begins, whatever the subject itself contains.
     */
    public static function subjectOfKey(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $at = strpos($key, '@');

        return $at === false ? null : substr($key, $at + 1);
    }
}
