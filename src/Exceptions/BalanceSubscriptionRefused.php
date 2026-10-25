<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A subscription paid from an internal balance was refused before anything was taken.
 *
 * Thrown by `BalanceSubscriptions` for a tier the merchant does not sell, for a buyer who already holds a running
 * subscription with the merchant, and for a change the subscription does not make: a subscription paid from a
 * balance changes its tier by ending and subscribing again, and it is not paused.
 *
 * Its messages interpolate their values into a sentence rather than assembling one from fragments, and the values
 * themselves are held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class BalanceSubscriptionRefused extends RuntimeException
{
    public static function tierNotSold(string $tierKey): self
    {
        return new self("The merchant sells no tier '{$tierKey}', so no subscription to it can be paid from a balance.");
    }

    public static function alreadySubscribed(): self
    {
        return new self('The buyer already holds a running subscription with this merchant. It has to end before another one starts.');
    }

    public static function cannotChange(string $change): self
    {
        return new self("A subscription paid from a balance cannot {$change}. End it and subscribe again instead.");
    }
}
