<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\ContentOwnership\AccessRevocations;
use Pushery\Billing\Contracts\DedupesOnReference;
use Pushery\Billing\Enums\RevokeReason;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Events\ChargebackReversed;
use RuntimeException;

/**
 * Gives back the access a chargeback ended, once the bank takes the chargeback back.
 *
 * A provider that reports a chargeback when it is raised, before the bank decides, has the access ended at once
 * ({@see RevokeAccessOnChargeback}, and the refund reading of a lost dispute). When the bank then reverses it, the
 * merchant has the money again and the customer has paid after all, so the work they bought is theirs again.
 *
 * Only what the chargeback ended comes back: a grant revoked for another reason stays revoked. Switchable like the
 * revocation, with its own key, because an install that keeps disputed works in place has nothing to give back, and
 * one that ends access on a dispute may still want a person to look before it returns.
 *
 * The reversed purchase and the credit note stay as they are. This gives back access and nothing else.
 */
final readonly class RestoreAccessOnChargebackReversal implements DedupesOnReference
{
    public function __construct(
        private AccessRevocations $revocations,
        private Repository $config,
    ) {}

    public function __invoke(ChargebackReversed $event): void
    {
        if ($this->config->get('billing.content_ownership.enabled') !== true) {
            return;
        }

        if ($this->config->get('billing.content_ownership.restore_on_chargeback_reversal') !== true) {
            return;
        }

        $this->revocations->reinstateForPayment($event->reference, RevokeReason::Chargeback, $event->reversedAt);
    }

    /**
     * Once per reversed chargeback, as the effects on `ChargebackReceived` act once per dispute.
     */
    public function dedupReference(BillingDomainEvent $event): string
    {
        if (! $event instanceof ChargebackReversed) {
            throw new RuntimeException('RestoreAccessOnChargebackReversal only handles ChargebackReversed events.');
        }

        return 'reversed:'.$event->disputeReference;
    }
}
