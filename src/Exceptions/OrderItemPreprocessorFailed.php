<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A configured preprocessor threw while pricing a cycle, so the cycle was not claimed.
 *
 * This is deliberately fatal to the cycle rather than survivable. A chain that fails halfway has priced
 * SOME of the lines, and continuing would charge the subscriber a total that no configuration reproduces
 * — then mark the cycle claimed, so the next tick skips it and nothing ever revisits the amount. The
 * failure would be permanent, silent, and in the customer's money.
 *
 * Failing here leaves the cycle unclaimed, which is the recoverable state: the next tick picks it up
 * again, and until then the subscription is visibly stuck rather than quietly wrong.
 *
 * The step's class name is in the message because a chain is configured, not written: whoever reads this
 * in a log is looking at a list of class names in a config file and needs to know which one to open.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class OrderItemPreprocessorFailed extends RuntimeException
{
    public static function in(string $step, Throwable $previous): self
    {
        return new self(
            "The order-item preprocessor [{$step}] threw while pricing a billing cycle, so the cycle was ".
            'left unclaimed and will be retried on the next run. Nothing was charged. Fix the step or '.
            'remove it from `billing.order_item_preprocessors`; the original failure is attached.',
            previous: $previous,
        );
    }
}
