<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Where a merchant stands with the platform, as opposed to what the provider currently reports about them.
 *
 * The two are deliberately separate. The capability flags are the provider's live answer and they move on
 * their own; this is the platform's own position, which changes only through a decision and stays until
 * another one is made. Reading the flags alone would mean a merchant re-suspended and re-instated by every
 * intermediate report a provider happens to send during a verification.
 */
enum MerchantStatus: string
{
    /** Routing may proceed, subject to the provider's capabilities. */
    case Active = 'active';

    /**
     * Something is wrong and no new money is routed, but the door is still open. This is the state a
     * failed re-verification produces: transfers stop, a reversal of money already sent still works, and
     * the merchant can come back without anybody re-onboarding them.
     */
    case Suspended = 'suspended';

    /**
     * The relationship is over and cannot be resumed from here. A merchant who disconnected their account
     * is unreachable in BOTH directions — transfers and reversals — which is why this is not just a
     * stronger suspension: it is the state in which a clawback has become impossible, and somebody owed
     * money has to be able to see that it has.
     */
    case Terminated = 'terminated';

    /** Whether the platform's own position permits routing. The provider still has its own say. */
    public function permitsRouting(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether a capability report may move the merchant back to active.
     *
     * A terminated merchant may not: the provider can keep reporting healthy capabilities for an account
     * long after its owner disconnected it from this platform, and letting that report reinstate them
     * would resume routing into a relationship that no longer exists.
     */
    public function isReinstatable(): bool
    {
        return $this === self::Suspended;
    }
}
