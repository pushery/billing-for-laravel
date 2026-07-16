<?php

declare(strict_types=1);

namespace Pushery\Billing\Entitlements;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\License;
use Pushery\Billing\Contracts\TierResolver;

/**
 * The owner-facing side of numeric entitlements: given a billing owner (not a tier key), what is the
 * ceiling for a resource, how much headroom is left, and does one more unit still fit. It resolves the
 * owner's tier once (via the app's {@see TierResolver}) and reads the ceiling from the {@see License},
 * so a consumer enforces a limit the same way everywhere instead of re-writing the `limit()` comparison
 * — the off-by-one that "did we mean `<` or `<=`?" invites — at every call site.
 *
 * The COUNT of what an owner has used stays the project's own concern (its `UsageProvider`, its own
 * tables); only the comparison against the ceiling lives here.
 */
final readonly class EntitlementsResolver
{
    public function __construct(private TierResolver $tiers, private License $license) {}

    /** The owner's numeric ceiling for a resource key, or null when the key is unlimited / not configured. */
    public function limit(Model $owner, string $key): ?int
    {
        return $this->license->limit($this->tiers->resolve($owner)->key, $key);
    }

    /**
     * How many units of $key the owner may still use given $used already consumed, or null when the key is
     * uncapped. Never negative: an owner already at or over the ceiling has 0 left, not a debt.
     */
    public function remaining(Model $owner, string $key, int $used): ?int
    {
        $limit = $this->limit($owner, $key);

        return $limit === null ? null : max(0, $limit - $used);
    }

    /**
     * Whether consuming $delta more of $key stays within the owner's ceiling, given $used already consumed.
     * An uncapped key always allows; otherwise the test is `used + delta <= limit`, so the unit that lands
     * exactly on the ceiling is allowed and the one past it is not.
     */
    public function allows(Model $owner, string $key, int $used, int $delta = 1): bool
    {
        $limit = $this->limit($owner, $key);

        return $limit === null || $used + $delta <= $limit;
    }
}
