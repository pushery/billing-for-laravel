<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Carbon\CarbonImmutable;
use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\ValueObjects\Money;

/**
 * A chargeback the bank took back: the money it withdrew is with the merchant again.
 *
 * Raised for a provider that reports a chargeback when it is received, before the bank has decided. `ChargebackReceived`
 * was raised for it then, and the effects registered for that event have run: access the payment bought may have ended
 * and the purchase may be reversed. Access comes back by itself where `billing.content_ownership.restore_on_chargeback_reversal`
 * is on, and a reversed add-on purchase goes back to what the payment still has refunded or charged back, with the credit
 * or the units the reversal took. A credit note against an invoice this package raised stays, and an invoice of its own
 * charges the credited amount again. It names the payment, the chargeback and the amount so a listener can act on the rest.
 */
final readonly class ChargebackReversed implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        /** The provider customer the payment belongs to, or empty where the payment names none. */
        public string $customerReference,
        /** The payment the chargeback was raised against, as `reference` on `ChargebackReceived`. */
        public string $reference,
        /** What the bank gave back. */
        public Money $amount,
        /** The provider's reference for the chargeback, as `disputeReference` on `ChargebackReceived`. */
        public string $disputeReference,
        /** When the provider says the chargeback was reversed, where it says so. */
        public ?CarbonImmutable $reversedAt = null,
        /**
         * What the payment still has refunded or charged back once this chargeback is reversed: its refunded amount plus
         * the chargebacks that still stand, the total `AddonRefunded` would carry for it now. A purchase the chargeback
         * reversed goes back to exactly this. Null where the provider does not say, and the purchase then stays as it is.
         */
        public ?Money $standingRefunded = null,
    ) {}

    /**
     * Restores an event a job queued before the standing total was named, as one that names none.
     *
     * @param  array{customerReference: string, reference: string, amount: Money, disputeReference: string, reversedAt?: ?CarbonImmutable, standingRefunded?: ?Money}  $data
     */
    public function __unserialize(array $data): void
    {
        $this->customerReference = $data['customerReference'];
        $this->reference = $data['reference'];
        $this->amount = $data['amount'];
        $this->disputeReference = $data['disputeReference'];
        $this->reversedAt = $data['reversedAt'] ?? null;
        $this->standingRefunded = $data['standingRefunded'] ?? null;
    }
}
