<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use DateTimeInterface;
use Pushery\Billing\Enums\MeteringPolicy;

/**
 * One metered usage dimension for the current period — the building block of a QuotaSnapshot. A
 * project renders 0..N of these (one app meters a single token budget, another meters two counts).
 * Pure value object: the math here is display-only; the ENFORCED limits live in each project's own
 * request path (e.g. an atomic reservation).
 */
final readonly class MeteredDimension
{
    public function __construct(
        public string $key,
        public string $label,
        public int $used,
        public ?int $limit,
        public string $unit,
        public string $period,
        public float $warnThreshold,
        public MeteringPolicy $policy,
        // The clock-authoritative moment this period's budget resets. Optional so dimensions that
        // are not calendar-bound (e.g. a per-session count) can omit it.
        public ?DateTimeInterface $resetAt = null,
    ) {}

    /** Fraction of the budget consumed (0.0 when the dimension is uncapped). */
    public function fraction(): float
    {
        if ($this->limit === null || $this->limit <= 0) {
            return 0.0;
        }

        return $this->used / $this->limit;
    }

    public function percent(): int
    {
        return (int) floor($this->fraction() * 100);
    }

    /** Units left, or null when uncapped. */
    public function remaining(): ?int
    {
        return $this->limit === null ? null : max(0, $this->limit - $this->used);
    }

    /** At or past the warning threshold (only meaningful for a capped dimension). */
    public function isWarning(): bool
    {
        return $this->limit !== null && $this->fraction() >= $this->warnThreshold;
    }

    /** Budget exhausted — the ceiling for this period is reached. */
    public function isOver(): bool
    {
        return $this->limit !== null && $this->used >= $this->limit;
    }
}
