<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A subscription canceled to a moment inside its period was asked to be taken back, or to be canceled again.
 *
 * Such a cancellation is final. On a driver that collects a period in advance, the rest of the period after that
 * moment may already have been refunded, and taking the cancellation back would hand the owner time they have
 * been paid back for. The owner subscribes again once it has ended.
 *
 * An `InvalidArgumentException`, which is what `SubscriptionActions::resume()` declares, so a caller that already
 * handles a refused action needs nothing new.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping a fragment
 * measures where the line was wrapped, not what a test asserts.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class EndInsidePeriodIsFinal extends InvalidArgumentException
{
    /** The owner asked to take the cancellation back. */
    public static function forResume(CarbonInterface $endsAt): self
    {
        return new self(
            'Cannot resume: the subscription was canceled to '.$endsAt->toIso8601String().', inside the period it '
            .'is in. That cancellation is final, because the rest of the period may have been refunded. Subscribe '
            .'again once it has ended.'
        );
    }

    /** A second cancellation to a date found the first one already in place. */
    public static function alreadyEnding(CarbonInterface $endsAt): self
    {
        return new self(
            'The subscription is already canceled to '.$endsAt->toIso8601String().', inside the period it is in. A '
            .'second cancellation to a date would refund the rest of the period a second time.'
        );
    }
}
