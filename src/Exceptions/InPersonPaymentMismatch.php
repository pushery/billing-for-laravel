<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\ValueObjects\Money;
use RuntimeException;

/**
 * A card payment at the counter was confirmed for a different amount than the sale went onto the reader with.
 *
 * The receipt states the tax decided for the gross the reader showed. Issued over a different amount, it would
 * state a split of money nobody paid, so nothing is issued and the confirmation fails loudly instead. The payment
 * was changed at the provider after the sale went up, and somebody has to look at why.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class InPersonPaymentMismatch extends RuntimeException
{
    public static function forPayment(string $payment, Money $collected, Money $gross): self
    {
        return new self("The payment '{$payment}' collected {$collected->format()}, but the sale went onto the reader for {$gross->format()}. No receipt was issued, because its tax was decided for the gross the reader showed.");
    }
}
