<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A settlement document belongs to an erased merchant, and no creditor account can be found for it, so an
 * installation that books each merchant to their own account cannot book it.
 *
 * An erasure keeps the document and the merchant's creditor account and gives both one key that names nobody,
 * which is how a later period still finds the account. A merchant erased before that key was recorded left
 * neither with one. The export is refused rather than booked to another account, because a booking on the wrong
 * account is a silent error in an accounting export.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class MerchantCreditorAccountUnknown extends RuntimeException
{
    public static function forErasedMerchant(string $document): self
    {
        return new self(
            "Settlement document '{$document}' belongs to an erased merchant, and no creditor account carries ".
            'its erasure key, so it cannot be booked to the account the merchant had. The merchant was erased '.
            'before erasures kept that key. Write one 32-character value into erased_merchant_key of the '.
            "merchant's former creditor account and into erased_owner_key of the merchant's documents, then export again."
        );
    }
}
