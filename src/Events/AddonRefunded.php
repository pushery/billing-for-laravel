<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\ValueObjects\Money;

/**
 * A charge was refunded (the neutral form of the provider's refund webhook). The paymentReference is
 * the provider payment id that a one-time add-on recorded when it was bought, so a reversal is matched
 * to the purchase it undoes. The amount is the provider's cumulative refunded total, not the delta —
 * the add-on ledger claws back only the part it has not already reversed, so two partial refunds and a
 * redelivery each do the right thing.
 *
 * A refund of anything that is not a tracked add-on (a subscription invoice, an ad-hoc charge) simply
 * matches no purchase and reverses nothing.
 */
final readonly class AddonRefunded implements BillingDomainEvent
{
    /**
     * @param  list<string>  $standingChargebacks  the provider's ids of the chargebacks the total counts, where it names them
     */
    public function __construct(
        public string $paymentReference,
        public Money $cumulativeRefunded,
        public ?string $reason = null,
        public array $standingChargebacks = [],
    ) {}

    /**
     * The refunded state of the payment, which each effect on this event acts on once.
     *
     * The payment, its cumulative total and why the money came back: a redelivery repeats them, and a further refund
     * raises the total. A chargeback the bank takes back lowers it again, so a new chargeback of the same amount
     * afterwards would repeat a state already acted on. Where the provider names the chargebacks the total counts,
     * they are part of the state, in any order, and a state that counts none reads as it always did.
     */
    public function stateReference(): string
    {
        $chargebacks = $this->standingChargebacks;
        sort($chargebacks);

        return sprintf('%s:refunded:%d:%s:%s', $this->paymentReference, $this->cumulativeRefunded->minorUnits, $this->cumulativeRefunded->currency, $this->reason ?? 'refund')
            .($chargebacks === [] ? '' : ':'.implode(',', $chargebacks));
    }

    /**
     * Restores an event a job queued before the chargebacks were named, as one that names none.
     *
     * @param  array{paymentReference: string, cumulativeRefunded: Money, reason?: ?string, standingChargebacks?: list<string>}  $data
     */
    public function __unserialize(array $data): void
    {
        $this->paymentReference = $data['paymentReference'];
        $this->cumulativeRefunded = $data['cumulativeRefunded'];
        $this->reason = $data['reason'] ?? null;
        $this->standingChargebacks = $data['standingChargebacks'] ?? [];
    }
}
