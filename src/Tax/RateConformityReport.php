<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

/**
 * What a conformity probe found when it compared the shipped rates against a source.
 *
 * ## Three outcomes, and collapsing any two of them is the defect
 *
 * A probe can **agree**, **disagree**, or **fail to ask**. The temptation is to treat the third as the
 * second — a red run is a red run — and that is exactly how a probe earns a reputation for lying. A DNS
 * failure reported as "the rates are wrong" trains an operator to ignore the one signal that matters, and
 * then a real drift arrives looking identical to the noise they have learned to dismiss.
 *
 * So `unreachable` is its own state, and it is NOT a drift. It means the question was never put.
 *
 * `refused` is the fourth thing and it is not a drift either: the source answered with two different
 * standard rates for one country, so there is nothing to compare against. That is a problem with the
 * answer, not with our table, and saying so is more useful than picking one and calling it a mismatch.
 *
 * An answer that compared no shipped country at all is no agreement either. A reader that fails to parse a
 * response returns no rows rather than an error, and every country then counts as not covered: silence
 * everywhere, which `compared` tells apart from a source that confirmed the table. Nothing was learned from
 * such an answer, so it exits like a source that could not be asked, while `unreachable` stays false.
 */
final readonly class RateConformityReport
{
    /**
     * @param  array<string, array{shipped: int, source: int}>  $drift  countries whose rate disagrees
     * @param  list<string>  $missingFromSource  shipped countries the source did not mention at all
     * @param  array<string, list<float>>  $refused  countries the source gave more than one standard rate for
     * @param  int  $compared  how many shipped countries the source gave a single standard rate for
     */
    public function __construct(
        public array $drift = [],
        public array $missingFromSource = [],
        public array $refused = [],
        public bool $unreachable = false,
        public ?string $situationOn = null,
        public int $compared = 0,
    ) {}

    /** Whether the shipped table and the source say the same thing everywhere they both speak, and they both speak somewhere. */
    public function agrees(): bool
    {
        return ! $this->unreachable && $this->compared > 0 && $this->drift === [] && $this->refused === [];
    }

    /** Whether the source answered without a single shipped country to compare or to refuse. */
    public function comparedNothing(): bool
    {
        return ! $this->unreachable && $this->compared === 0 && $this->refused === [];
    }

    /**
     * The process exit code, keeping "could not ask" distinct from "found a difference".
     *
     * 0 agreement · 1 drift or an unusable answer · 2 nothing was learned: the source could not be reached, or
     * its answer compared no shipped country.
     */
    public function exitCode(): int
    {
        if ($this->unreachable || $this->comparedNothing()) {
            return 2;
        }

        return $this->agrees() ? 0 : 1;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'agrees' => $this->agrees(),
            'unreachable' => $this->unreachable,
            'situation_on' => $this->situationOn,
            'compared' => $this->compared,
            'drift' => $this->drift,
            'missing_from_source' => $this->missingFromSource,
            'refused' => $this->refused,
        ];
    }
}
