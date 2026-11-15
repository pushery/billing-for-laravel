<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A mandate that was not handed to another owner, with the reason a caller can branch on.
 *
 * `reason` is a fixed word per cause: `holds_a_default` where the owner it was handed to already has a default
 * payment method at the provider, which the handover would replace without anybody choosing that, and
 * `another_customer` where that owner already belongs to another customer of the provider than the mandate does.
 *
 * The messages are sentence text rather than behavior, so like every exception class this one opts out of
 * the three mutators that measure where a line of prose was wrapped. The values themselves are held by a
 * dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class MandateHandOverRefused extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function holdsADefault(string $provider): self
    {
        return new self('holds_a_default', sprintf('The owner the mandate is handed to already has a default payment method at %s, and the handover would replace it without anybody choosing that.', $provider));
    }

    public static function anotherCustomer(string $provider): self
    {
        return new self('another_customer', sprintf('The owner the mandate is handed to already belongs to another %s customer than the mandate does.', $provider));
    }
}
