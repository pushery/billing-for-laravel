<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use DateTimeInterface;
use Pushery\Billing\ValueObjects\MerchantAccountReference;
use Pushery\Billing\ValueObjects\TransferResult;

/**
 * Finds the transfer that already moved a sale's share, for a retry whose earlier attempt may have reached the provider.
 *
 * A retry asks again under the sale's idempotency key, and a provider answers from a key only while it keeps it:
 * Stripe keeps one for 24 hours, and a share can wait far longer, behind a merchant's withheld payouts for one. An
 * earlier attempt whose transfer the provider made and whose answer was lost would then be made a second time, and the
 * merchant paid twice. A transfer names the payment that funds it, so the transfer to the same merchant funded by the
 * same payment is the one that moved this sale's share.
 *
 * Optional beside {@see MovesMerchantShare}: a provider that cannot look a transfer up keeps the retry it has.
 */
interface FindsMovedShare
{
    /**
     * The transfer to $destination funded by $sourceCharge and created no earlier than $notBefore, or null when the
     * provider holds none.
     *
     * $sourceCharge is the payment reference the ledger holds, as {@see MovesMerchantShare::transferShare()} takes it.
     */
    public function transferOf(MerchantAccountReference $destination, string $sourceCharge, DateTimeInterface $notBefore): ?TransferResult;
}
