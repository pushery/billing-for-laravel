<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonInterface;
use Pushery\Billing\Enums\SubscriptionState;

/**
 * One customer's subscription state on one merchant, as a content gate reads it.
 *
 * This is the read side of the boundary the package holds: billing owns MONEY and SUBSCRIPTION-STATE, the
 * consumer owns CONTENT-ACL. So a grant answers "what tier, in what state, over what window" and NOTHING
 * about what that tier unlocks — the consumer decides that. It is deliberately provider-free: it is built
 * from the local subscription row, never a live provider read, so a content check on a hot path never calls
 * out to a payment provider.
 *
 * A tier NEVER implies access on its own; access is the CONJUNCTION of an access-granting state and, for a
 * cumulative check, a high-enough rank. `atLeast` is fail-closed: an unknown tier ranks below every real one,
 * so "at least free" fails on it rather than passing.
 */
final readonly class SubscriptionGrant
{
    public function __construct(
        /** The merchant this grant is on, or null for the platform's own subscription. */
        public ?MerchantScope $merchant,
        public TierIdentity $tier,
        public SubscriptionState $state,
        public ?CarbonInterface $windowStart = null,
        public ?CarbonInterface $windowEnd = null,
    ) {}

    /** Whether the state grants access at all — the same reading every account screen uses. */
    public function grantsAccess(): bool
    {
        return $this->state->grantsAccess();
    }

    /**
     * Whether this grants access AT this tier or higher: an access-granting state AND a rank at least the one
     * asked for. Fail-closed — an unknown tier ranks -1, so it never satisfies a cumulative check.
     */
    public function atLeast(int $level): bool
    {
        return $this->grantsAccess() && $this->tier->level >= $level;
    }

    /**
     * Whether the instant falls inside the grant's window. An open end (a null bound) is unbounded on that
     * side, so a subscription with no recorded period covers every instant — state, not the clock, gates it.
     */
    public function coversInstant(CarbonInterface $at): bool
    {
        if ($this->windowStart instanceof CarbonInterface && $at->lessThan($this->windowStart)) {
            return false;
        }

        return ! $this->windowEnd instanceof CarbonInterface || ! $at->greaterThan($this->windowEnd);
    }
}
