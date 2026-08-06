<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A correction to a period that can no longer be corrected.
 *
 * It is refused rather than dropped or folded into the current period, and the difference matters. A
 * correction that vanishes is indistinguishable from one that was never owed — nothing is short, nothing
 * errors, and the return simply omits money that moved. One folded into the current period declares a
 * country's tax in the wrong period, which is a misdeclaration rather than an omission.
 *
 * Both are worse than a refusal, because a refusal is the one outcome somebody has to decide about.
 */
final class CorrectionOutsideWindow extends RuntimeException
{
    public static function forPeriod(string $origin, string $declaredIn, int $windowYears): self
    {
        return new self(
            "A correction to {$origin} cannot be declared in {$declaredIn}: the window is {$windowYears} "
            .'year(s) from the date the original return was due, and it has passed. The correction is '
            .'refused rather than dropped or moved into the current period — dropping it would leave money '
            .'that moved undeclared with nothing looking wrong, and moving it would declare a country\'s '
            .'tax in the wrong period. Take it up with the authority instead.'
        );
    }
}
