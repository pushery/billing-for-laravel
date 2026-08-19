<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use DateTimeInterface;
use InvalidArgumentException;
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
        // THE FORECAST PAIR, and it is supplied rather than computed.
        //
        // A rate needs history, and this object knows only the current period. Worse, "per day" is a
        // claim about a project's own counting: a dimension reset mid-period, a backfilled import, or a
        // meter that counts only business days each give a different honest answer. Computing it here
        // would make one of them the package's opinion and be wrong for the rest.
        //
        // So a provider fills both or neither, and a screen renders them only when `hasForecast()`.
        // Optional, therefore every existing caller keeps working untouched.
        public ?float $ratePerDay = null,
        public ?DateTimeInterface $exhaustedAt = null,
    ) {
        // Usage does not run backwards. A negative rate would draw a line the wrong way and put the
        // exhaustion date in the past, so it is refused where it enters rather than where it shows.
        if ($ratePerDay !== null && $ratePerDay < 0.0) {
            throw new InvalidArgumentException(
                "ratePerDay for dimension '{$key}' is {$ratePerDay}; usage does not run backwards.",
            );
        }
    }

    /**
     * Whether this dimension can answer "how long will it last".
     *
     * Both halves or neither: a rate without a date renders as "25/day" with no answer to "until
     * when", and a date without a rate is a deadline nobody can check. Deciding it here keeps every
     * consumer from resolving that ambiguity its own way.
     */
    public function hasForecast(): bool
    {
        return $this->ratePerDay !== null && $this->exhaustedAt instanceof DateTimeInterface;
    }

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
