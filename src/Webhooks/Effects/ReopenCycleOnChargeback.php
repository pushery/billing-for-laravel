<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Pushery\Billing\Contracts\DedupesOnReference;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Events\ChargebackReceived;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\LocalBillingEngine;
use RuntimeException;

/**
 * Opens a collected cycle again when the payer takes its payment back.
 *
 * A direct debit can be taken back for weeks after it settled, and without this the period stays paid on the row while
 * the money is gone: no dunning, no retry, a subscription that goes on as if nothing happened. The engine decides what
 * the cycle becomes ({@see LocalBillingEngine::chargedBack()}); this stays a lookup and a handoff, as
 * {@see FailCycleOnPayment} does.
 *
 * Deduped on the chargeback rather than on the delivery. Mollie reports a chargeback as a ping on the payment, whose
 * status stays `paid`, so it arrives under the delivery key of the payment it takes back, and a second chargeback of the
 * same payment arrives under that key again. Each chargeback is its own claim.
 */
final readonly class ReopenCycleOnChargeback implements DedupesOnReference
{
    public function __construct(private BillingManager $manager) {}

    public function __invoke(ChargebackReceived $event): void
    {
        $engine = $this->manager->driver()->engine();

        if (! $engine instanceof LocalBillingEngine) {
            return;
        }

        $engine->chargedBack($event->reference, $event->amount, $event->disputeReference);
    }

    public function dedupReference(BillingDomainEvent $event): string
    {
        if (! $event instanceof ChargebackReceived) {
            throw new RuntimeException('ReopenCycleOnChargeback only handles ChargebackReceived events.');
        }

        return $event->disputeReference ?? $event->reference;
    }
}
