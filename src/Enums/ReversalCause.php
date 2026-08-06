<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Why money came back from a merchant.
 *
 * The two are not variations on one another and must never be collapsed. A refund is an agreed unwinding:
 * the platform asks the provider to return the buyer's money and to reverse the merchant's share in the
 * same breath, and the three parties net to zero. A lost dispute has already happened by the time anybody
 * hears about it — the provider has debited the platform in full and charged a fee for the trouble, and
 * the merchant's transfer is untouched until somebody reverses it separately.
 *
 * Getting this wrong in either direction is expensive. Treating a lost dispute as a refund tries to reverse
 * a transfer through a refund call that has nothing left to refund; treating a refund as a dispute books a
 * fee that was never charged.
 */
enum ReversalCause: string
{
    /** The platform returned the buyer's money and the merchant's share with it. */
    case Refund = 'refund';

    /**
     * The buyer's bank took the money back and the platform lost the case.
     *
     * The merchant's transfer is reversed on its own, after the fact, and the provider's dispute fee is a
     * cost the platform bears that no reversal returns.
     */
    case DisputeLost = 'dispute_lost';

    /** Whether the provider charges the platform for handling this kind of reversal. */
    public function carriesProviderFee(): bool
    {
        return $this === self::DisputeLost;
    }
}
