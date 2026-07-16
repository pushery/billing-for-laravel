<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * One rung of the multi-level dunning ladder: how many days after the delinquency clock started
 * this level fires, plus the fee it applies. Modelled as a value object rather than an enum because
 * the ladder is config-driven — a project defines as many rungs as it needs (config `billing.dunning`).
 * The delinquency clock is a timestamp, never a gateway status, so the ladder is outage-safe.
 */
final readonly class DunningLevel
{
    public function __construct(
        public int $position,
        public int $afterDays,
        public Money $fee,
        public string $label,
    ) {
        if ($afterDays < 0) {
            throw new InvalidArgumentException('A dunning level cannot fire before delinquency begins.');
        }
    }

    /** Whether this rung applies a non-zero fee. */
    public function hasFee(): bool
    {
        return ! $this->fee->isZero();
    }

    /** The moment this rung fires, given when the delinquency clock started. */
    public function triggersAt(DateTimeInterface $delinquentSince): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($delinquentSince)
            ->add(new DateInterval("P{$this->afterDays}D"));
    }

    /** Whether this rung has been reached at the given moment. */
    public function isReachedAt(DateTimeInterface $delinquentSince, DateTimeInterface $now): bool
    {
        return $now >= $this->triggersAt($delinquentSince);
    }
}
