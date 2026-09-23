<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Carbon\CarbonImmutable;
use Pushery\Billing\Enums\DisputeReason;
use Pushery\Billing\ValueObjects\Money;

/**
 * A buyer disputed a payment, and the provider opened a case that can still be answered.
 *
 * The outcome arrives later, as `ChargebackReceived` when the case is lost. This event is the other end of the
 * same case: it carries what a host needs while the case is open, above all the moment the provider stops
 * accepting evidence. The evidence goes back through `SubmitsDisputeEvidence`, which takes the dispute and
 * account references exactly as this event names them.
 *
 * Nothing is sent to the provider when this event is raised. Whether a case is worth answering, and with what,
 * is the host's decision; the package only makes sure the host hears about it while there is still time.
 */
final readonly class DisputeOpened implements BillingDomainEvent
{
    public function __construct(
        /** The provider's reference for the dispute, which is what `SubmitsDisputeEvidence` takes. */
        public string $disputeReference,
        /** The payment the dispute was raised against. */
        public string $paymentReference,
        /** The disputed amount. It can be less than the payment, because part of an order can be disputed. */
        public Money $amount,
        /** What the dispute is about, mapped to what this package knows. */
        public DisputeReason $reason,
        /**
         * The reason exactly as the provider stated it.
         *
         * `$reason` folds every code this package does not act on into `Unknown`, which is right for the
         * correction a lost case owes and wrong for choosing the evidence that answers an open one: a duplicate
         * charge and a canceled subscription are answered with different documents.
         */
        public ?string $reasonCode = null,
        /** The last moment the provider accepts evidence, in UTC. Null where the provider stated none. */
        public ?CarbonImmutable $evidenceDueBy = null,
        /**
         * The connected account the dispute lives on, which `SubmitsDisputeEvidence` takes as well.
         *
         * Null on the platform's own account, including a routed sale whose payment the platform took whole:
         * that dispute lives with the platform even though a merchant made the sale.
         */
        public ?string $accountReference = null,
        /** The merchant the sale was routed to, where it was. Null for a single-seller sale. */
        public ?string $merchantReference = null,
    ) {}
}
