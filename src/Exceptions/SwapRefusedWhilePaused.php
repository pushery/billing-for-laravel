<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use InvalidArgumentException;

/**
 * A plan change was asked of a subscription that rests.
 *
 * While a subscription is paused nothing is collected, and a swap prorates: an upgrade would charge the rest of the
 * period at once, measured to a period end the pause has not moved yet. The change is refused until the subscription
 * goes on. A change scheduled before the pause is not lost: it waits, and it moves with the period when the
 * subscription is taken up again.
 *
 * An `InvalidArgumentException`, which is what `SubscriptionActions::swap()` declares, so a caller that already
 * handles a refused swap needs nothing new, and a screen that wants to say why catches this class.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class SwapRefusedWhilePaused extends InvalidArgumentException
{
    /** A swap of the paused subscription, asked by a person or come due on its schedule. */
    public static function forSwap(): self
    {
        return new self('Cannot change the plan while the subscription is paused: take it up again with unpause() first.');
    }
}
