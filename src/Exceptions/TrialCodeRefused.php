<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\Discounts\TrialCodes;
use RuntimeException;

/**
 * A code entered for a trial gives none ({@see TrialCodes::assertGives()}). One message for every reason, so the
 * answer never tells a stranger whether a code exists.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class TrialCodeRefused extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This code does not give a trial.');
    }
}
