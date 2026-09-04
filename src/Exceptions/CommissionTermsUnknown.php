<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A PARTIAL reversal was asked for on a routed sale whose commission terms were never recorded.
 *
 * A partial clawback is a DIFFERENCE: what the merchant holds now, less what they would have been paid on
 * what is left of the sale. The second half needs the rate and the flat amount the sale was priced under,
 * and a row written before those columns existed does not have them.
 *
 * Refused rather than approximated, and the alternative is worth naming because it looks reasonable:
 * reading today's configuration would produce a figure, the figure would balance, and it would be the
 * amount a DIFFERENT sale owed. That is exactly the mistake the frozen columns exist to prevent, arrived at
 * one layer up.
 *
 * A FULL reversal of the same row is fine and is not refused. With nothing left of the sale there is no
 * remainder to price: everything the merchant still holds comes back, and every rate gives that same answer.
 * So the refusal is as narrow as the missing fact actually is.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class CommissionTermsUnknown extends RuntimeException
{
    public static function forPartialReversal(string $chargeReference): self
    {
        return new self(
            "Charge {$chargeReference} was routed before its commission terms were recorded, so what the "
            .'merchant would have been paid on the remainder cannot be computed. A partial refund of it is '
            .'refused rather than clawed back at a rate this sale was never made under. A full refund is '
            .'unaffected: with no remainder to price, everything still held comes back.'
        );
    }
}
