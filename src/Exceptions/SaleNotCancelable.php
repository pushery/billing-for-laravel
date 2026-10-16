<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A sale stands on a card reader, and the provider takes it off only at the reader itself.
 *
 * Mollie never reports an open terminal payment as cancelable, and its cancel endpoint refuses one. The sale then
 * stays on the terminal until the buyer pays it, somebody presses the cancel button on the device, or it expires,
 * and the terminal takes no other sale meanwhile. Saying so is what keeps an application from treating the sale as
 * gone while the buyer can still pay it.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class SaleNotCancelable extends RuntimeException
{
    public static function atTheReaderOnly(string $reader, string $payment): self
    {
        return new self("The sale on the reader '{$reader}' (payment {$payment}) can be canceled only at the reader: press the cancel button on the device. Until then it stays on the reader, and the buyer can still pay it.");
    }
}
