<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * The window a counter counts over — a calendar year, a calendar quarter, or any span somebody names.
 *
 * ## Why this exists rather than an `int $year`
 *
 * The three counters this package owes are the same machine with different settings, and the settings that
 * differ are the WINDOW and the BASIS. A seam that takes a year can serve exactly one of them: the annual
 * threshold monitor. A quarterly reporting counter cannot use it, so it would have to bring its own counting
 * — and two counting systems over one set of transactions drift apart at the first refund, which is the
 * failure the shared seam exists to prevent.
 *
 * ## Half-open, and that is the whole point of the boundary
 *
 * `[from, until)` — the start is included and the end is not. A closed range has to name its last instant,
 * and whatever it names is wrong: end-of-day drops the last hours on a timestamp column, and 23:59:59 drops
 * the final second, which is exactly where a year-end sale lands when somebody is watching the clock. The
 * half-open form has no such instant to get wrong: December's window ends where January's begins.
 */
final readonly class CountingPeriod
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $until,
    ) {}

    /** A calendar year, in the caller's timezone. */
    public static function year(int $year): self
    {
        // `parse` rather than `create`, because `create` is nullable by signature: it answers null for a
        // date it cannot make. A window whose start is null is not a window, and a counter handed one would
        // sum over everything -- so the shape that cannot produce one is the right one to build from.
        $start = CarbonImmutable::parse(sprintf('%04d-01-01 00:00:00', $year));

        return new self($start, $start->addYear());
    }

    /**
     * A calendar quarter, numbered 1 to 4.
     *
     * Out-of-range numbers are clamped rather than refused: this is a window, and a caller asking for the
     * fifth quarter has a bug the counter cannot fix and should not decide the consequence of. Clamping
     * keeps the window a real one, so the count is honest about the period it names.
     */
    public static function quarter(int $year, int $quarter): self
    {
        $start = CarbonImmutable::parse(sprintf('%04d-%02d-01 00:00:00', $year, 1 + 3 * (max(1, min(4, $quarter)) - 1)));

        return new self($start, $start->addMonths(3));
    }

    /** Whether an instant falls inside the window. */
    public function contains(CarbonImmutable $moment): bool
    {
        return $moment >= $this->from && $moment < $this->until;
    }
}
