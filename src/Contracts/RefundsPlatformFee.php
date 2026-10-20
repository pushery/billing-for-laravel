<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\Money;

/**
 * Gives back part of the platform's fee on a routed payment, in the amount the ledger says comes back.
 *
 * A provider asked to share the fee out with a refund gives back a share of the fee as it was first withheld. The
 * ledger recomputes the fee on what remains of the sale, because a fixed part is not halved by a half refund, and a
 * fee corrected after the payment is not the fee the provider withheld. The two figures part on exactly those sales,
 * so the platform's fee is given back separately, with the ledger's figure, under a key of the refund it belongs to.
 *
 * Separate from `ReversesMerchantShare`: that moves the merchant's share back to the platform, this moves the
 * platform's fee back to the merchant, and a driver may support one without the other.
 */
interface RefundsPlatformFee
{
    /**
     * Give back this much of the fee on the payment, once for the refund the key names however often it is asked.
     *
     * Never more than the fee still held: a provider refuses more, and asking for it would fail the whole call over
     * the part that cannot come back.
     *
     * @param  string  $chargeReference  the payment, as the ledger row names it
     * @param  string  $refundKey  the refund this belongs to, which a repeated call finds rather than refunding twice
     * @return ?Money what came back under this key, or null where the payment carries no fee to give back
     */
    public function refundPlatformFee(string $chargeReference, Money $amount, string $refundKey): ?Money;
}
