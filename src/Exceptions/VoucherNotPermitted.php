<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * Something a voucher is not allowed to do, refused where it was attempted.
 *
 * Each case is one of the properties that keeps the instrument outside regulated money. They are not
 * configurable, and that is the whole point: a switch that turned one off would turn the voucher into
 * something a license is needed for, and the switch would look like any other setting.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class VoucherNotPermitted extends RuntimeException
{
    public static function featureDisabled(): self
    {
        return new self(
            'Vouchers are switched off. They are off by default because a balance customers pay into is a '
            .'supervised question once it can be recharged, cashed out or handed on — this one can do none '
            .'of those, but it is still a thing to turn on deliberately rather than to find running.'
        );
    }

    public static function overRemainingValue(string $code, int $requested, int $remaining): self
    {
        return new self(
            "Voucher {$code} has {$remaining} left and {$requested} was asked of it. The difference is not "
            .'credit: allowing it would let a voucher pay for more than was ever paid into it, which is '
            .'lending, and the shortfall would sit in the books as revenue nobody received.'
        );
    }

    public static function alreadyExpired(string $code): self
    {
        return new self(
            "Voucher {$code} has expired and its remaining value has been taken to income. Spending it now "
            .'would take that income back without any document saying so.'
        );
    }
}
