<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\ReversalCause;
use Pushery\Billing\ValueObjects\Money;

/**
 * Money came back from a merchant.
 *
 * The event exists so a consumer's own ledger can reverse its payout entry without knowing how the package
 * stores charges — and, more to the point, without having to work out for itself how much came back. That
 * figure is not a share of what was paid out and cannot be derived by anybody downstream.
 *
 * The provider's dispute fee travels as its OWN amount rather than being netted off the reversal. It is a
 * separate service the provider charged the platform for, with its own place in the books; folding it into
 * the reversal would state that the merchant returned more than they did, and would hide a cost the
 * platform actually bore behind a number that looks like a correction.
 */
final readonly class MerchantTransferReversed implements BillingDomainEvent
{
    public function __construct(
        public Model $merchant,
        public string $provider,
        public string $chargeReference,
        /** What came back from the merchant. */
        public Money $amount,
        /** What the platform returned of its own commission, which can be nothing. */
        public Money $feeReturned,
        public ReversalCause $cause,
        /**
         * The provider's charge for handling a dispute, when there was one.
         *
         * Null for a refund, because none was charged — not zero. A zero would say the provider charged
         * nothing for a dispute that happened, which is a different claim from no dispute having happened.
         */
        public ?Money $disputeFee = null,
    ) {}

    /**
     * Whether the platform is out of pocket on this reversal beyond the margin it gave up.
     *
     * A refund nets to zero across the three parties. A lost dispute cannot: the provider's fee is real
     * money the platform spent and nobody returns it. Code that expects every reversal to balance is
     * either not modeling the fee or is about to book it somewhere it does not belong.
     */
    public function carriesRealLoss(): bool
    {
        return $this->disputeFee instanceof Money && ! $this->disputeFee->isZero();
    }
}
