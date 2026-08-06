<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A delayed-payout arrangement that cannot be operated as configured.
 *
 * Both cases refuse at boot rather than at the first sale, and for the same reason: the failure they prevent
 * happens weeks later, to money that has already been taken from a buyer. A deadline the provider will not
 * honor is discovered on the day it passes; an account type that gives no payout control is discovered when
 * the first payout leaves early. Neither is a state to find out about in production.
 */
final class BuyerProtectionMisconfigured extends RuntimeException
{
    public static function decisionBeyondProviderLimit(int $decideAfterDays, int $limitDays, int $marginDays): self
    {
        return new self(
            "A decision is due after {$decideAfterDays} days, and the provider will not delay a payout "
            ."beyond {$limitDays} — leaving {$marginDays} days of margin, which is not enough. The clock has "
            .'to finish before the provider stops waiting: past that point the money goes out whatever this '
            .'says, and the buyer protection is a promise the system cannot keep.'
        );
    }

    public static function accountTypeWithoutPayoutControl(string $configured): self
    {
        return new self(
            "Connected accounts of type '{$configured}' pay out on the provider's own schedule, which leaves "
            .'no way to hold a payout back. The whole arrangement rests on being able to delay one, so this '
            .'is refused rather than run as an arrangement that only appears to protect the buyer.'
        );
    }

    public static function alreadySettled(string $chargeReference, string $state): self
    {
        return new self(
            "The hold on {$chargeReference} is already {$state}; there is nothing left to decide. Moving it "
            .'again would send the same money a second time, and the second instruction is indistinguishable '
            .'from the first once it has gone.'
        );
    }
}
