<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Where a delayed payout stands between the buyer paying and the seller being paid.
 *
 * The money never leaves the provider's sphere while this runs — the platform triggers a release, it does
 * not hold anything. That distinction is not a wording preference: holding other people's money is a
 * regulated activity, and a system that does it accidentally is doing it without a license.
 *
 * The states are deliberately more than "open" and "done". A payout waiting for the buyer to confirm and one
 * waiting on a raised dispute are the same amount in the same place, and only the state says which clock is
 * running — one of them has stopped.
 */
enum BuyerProtectionState: string
{
    /** Paid, and waiting for the buyer to say they got what they bought. */
    case AwaitingConfirmation = 'awaiting_confirmation';

    /** Cleared to go out, not yet gone. */
    case ReleasePending = 'release_pending';

    /** The seller has it. Terminal. */
    case Released = 'released';

    /** The buyer says something is wrong. The confirmation clock STOPS here. */
    case Disputed = 'disputed';

    /** Nobody resolved it in time, so somebody has to now — the platform does not decide it. */
    case ResolutionRequired = 'resolution_required';

    /** The buyer got their money back. Terminal. */
    case Refunded = 'refunded';

    /** Whether this hold is finished, one way or the other. */
    public function settled(): bool
    {
        return $this === self::Released || $this === self::Refunded;
    }

    /**
     * Whether the confirmation clock is running.
     *
     * A dispute stops it. Letting it run would auto-release money the buyer has actively objected to, which
     * is the one outcome the whole arrangement exists to prevent.
     */
    public function clockRuns(): bool
    {
        return $this === self::AwaitingConfirmation;
    }
}
