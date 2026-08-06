<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

/**
 * What an importer found, written down beside the snapshot and never into it.
 *
 * ## Why the importer may not write
 *
 * This is "a silently wrong value is worse than an old one" cast in code. A manipulated or simply misparsed
 * response cannot put a rate into production, because the thing that fetched it **has no write access to the
 * money path**. The snapshot changes when a person changes it, and the header then records who.
 *
 * A proposal is therefore a separate file with no authority. Reviewing it is a deliberate act; ignoring it
 * leaves the shipped rates exactly as they were, which is the safe direction.
 *
 * ## The outcome that must never be confused with "nothing changed"
 *
 * `unreachable` is its own state and it exits differently. Booking "could not ask" as "no change" is
 * precisely how a silent network failure becomes a year of stale rates — and in every log it looks like a
 * successful run. That confusion is the incident, one level up.
 */
final readonly class RateProposal
{
    /**
     * @param  array<string, int>  $proposed  the rates a source offered, in basis points
     * @param  array<string, array{from: int, to: int}>  $changes  what would move, and to what
     * @param  array{implausible: list<string>, vanished: list<string>, notable: list<string>}  $assessment
     */
    public function __construct(
        public array $proposed = [],
        public array $changes = [],
        public array $assessment = ['implausible' => [], 'vanished' => [], 'notable' => []],
        public bool $unreachable = false,
        public ?string $situationOn = null,
    ) {}

    /** Whether anything at all would move. */
    public function hasChanges(): bool
    {
        return $this->changes !== [];
    }

    /** Whether this proposal is fit to be written down for a person to look at. */
    public function usable(): bool
    {
        return ! $this->unreachable && RatePlausibility::usable($this->assessment);
    }

    /**
     * The process exit code.
     *
     * 0 nothing to do · 1 a proposal is waiting · **2 the source could not be asked, or answered something
     * that cannot be trusted**. The third is the one that must not collapse into the first.
     */
    public function exitCode(): int
    {
        if (! $this->usable()) {
            return 2;
        }

        return $this->hasChanges() ? 1 : 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'unreachable' => $this->unreachable,
            'situation_on' => $this->situationOn,
            'usable' => $this->usable(),
            'changes' => $this->changes,
            'assessment' => $this->assessment,
            'proposed' => $this->proposed,
        ];
    }
}
