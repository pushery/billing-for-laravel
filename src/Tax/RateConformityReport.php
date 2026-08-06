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
 */
final readonly class RateConformityReport
{
    /**
     * @param  array<string, array{shipped: int, source: int}>  $drift  countries whose rate disagrees
     * @param  list<string>  $missingFromSource  shipped countries the source did not mention at all
     * @param  array<string, list<float>>  $refused  countries the source gave more than one standard rate for
     */
    public function __construct(
        public array $drift = [],
        public array $missingFromSource = [],
        public array $refused = [],
        public bool $unreachable = false,
        public ?string $situationOn = null,
    ) {}

    /** Whether the shipped table and the source say the same thing everywhere they both speak. */
    public function agrees(): bool
    {
        return ! $this->unreachable && $this->drift === [] && $this->refused === [];
    }

    /**
     * The process exit code, keeping "could not ask" distinct from "found a difference".
     *
     * 0 agreement · 1 drift or an unusable answer · 2 the source could not be reached.
     */
    public function exitCode(): int
    {
        if ($this->unreachable) {
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
            'drift' => $this->drift,
            'missing_from_source' => $this->missingFromSource,
            'refused' => $this->refused,
        ];
    }
}
