<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonInterface;
use Pushery\Billing\Enums\VersionMode;

/**
 * Which version of a work somebody gets, worked out for a moment in time.
 *
 * ## Why this is a resolution and not the policy
 *
 * The policy is what was sold; this is what a delivery path must do today. They are not the same object
 * because the same policy answers differently on different days: a windowed grant is `Latest` while its
 * window is open and `Bounded` the day after it closes. Handing the delivery path the policy and asking it
 * to work that out would put the update rules in every consumer, slightly differently.
 *
 * ## The enrichment axis only
 *
 * These four policies are about NEW content — later editions, added material. They are the creator's to
 * choose. Conformity updates (a fix, a security patch) are a different axis, mandatory rather than
 * selectable, and deliberately not expressible here: `Frozen` freezes what somebody is ENTITLED to, and says
 * nothing about what the seller still owes. Mixing them would let a product setting waive an obligation that
 * cannot be waived.
 *
 * ## Earlier versions ride alongside the mode
 *
 * "Newest only" and "newest plus the back versions" both resolve to `Latest` — they differ in what else is
 * reachable, not in what is handed over first. Keeping that a separate flag rather than a fourth mode is why
 * a delivery path can switch on the mode without a branch it does not care about.
 */
final readonly class VersionResolution
{
    public function __construct(
        public VersionMode $mode,
        /** The exact version a pinned grant names. Only ever set for `Pinned`. */
        public ?string $pinnedVersionReference = null,
        /** The date a bounded grant stops at. Only ever set for `Bounded`. */
        public ?CarbonInterface $boundaryAt = null,
        /** Whether versions older than the resolved one are reachable too. */
        public bool $includesEarlierVersions = false,
    ) {}

    public static function latest(bool $includesEarlierVersions = false): self
    {
        return new self(VersionMode::Latest, includesEarlierVersions: $includesEarlierVersions);
    }

    public static function pinnedTo(string $versionReference, bool $includesEarlierVersions = false): self
    {
        return new self(VersionMode::Pinned, pinnedVersionReference: $versionReference, includesEarlierVersions: $includesEarlierVersions);
    }

    public static function boundedAt(CarbonInterface $boundary, bool $includesEarlierVersions = false): self
    {
        return new self(VersionMode::Bounded, boundaryAt: $boundary, includesEarlierVersions: $includesEarlierVersions);
    }

    /**
     * The version to hand over, or null when there is none to hand over.
     *
     * Null is a real answer here, and there are two ways to reach it. A work with nothing published yet has
     * no version — that is the pre-order case, and it is a state, not a failure. A pin that names a version
     * the catalog no longer lists also answers null, deliberately: silently handing over the newest one
     * instead would give somebody the opposite of what a frozen grant promised, and would do it invisibly.
     *
     * A version published after the moment asked about is never picked. "Newest" means newest SO FAR, or a
     * back-dated access check would answer with a file that did not exist yet.
     *
     * @param  list<ContentVersion>  $versions
     */
    public function pick(array $versions, CarbonInterface $at): ?ContentVersion
    {
        if ($this->mode === VersionMode::Pinned) {
            foreach ($versions as $version) {
                if ($version->reference === $this->pinnedVersionReference) {
                    return $version;
                }
            }

            return null;
        }

        $ceiling = $this->mode === VersionMode::Bounded && $this->boundaryAt instanceof CarbonInterface && $this->boundaryAt->lessThan($at)
            ? $this->boundaryAt
            : $at;

        $newest = null;

        foreach ($versions as $version) {
            if ($version->releasedAt->greaterThan($ceiling)) {
                continue;
            }

            if (! $newest instanceof ContentVersion || $version->releasedAt->greaterThan($newest->releasedAt)) {
                $newest = $version;
            }
        }

        return $newest;
    }

    /**
     * Every version this grant may download, newest first.
     *
     * Without the earlier-versions flag this is the resolved version alone — which is what "you always get
     * the newest" means to somebody who then asks for last year's edition and is told no. With it, everything
     * up to and including the resolved one.
     *
     * @param  list<ContentVersion>  $versions
     * @return list<ContentVersion>
     */
    public function reachable(array $versions, CarbonInterface $at): array
    {
        $resolved = $this->pick($versions, $at);

        if (! $resolved instanceof ContentVersion) {
            return [];
        }

        if (! $this->includesEarlierVersions) {
            return [$resolved];
        }

        $earlier = array_values(array_filter(
            $versions,
            static fn (ContentVersion $version): bool => ! $version->releasedAt->greaterThan($resolved->releasedAt),
        ));

        usort($earlier, static fn (ContentVersion $a, ContentVersion $b): int => $b->releasedAt->getTimestamp() <=> $a->releasedAt->getTimestamp());

        return $earlier;
    }
}
