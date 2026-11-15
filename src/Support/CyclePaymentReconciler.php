<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Http\Request;
use Pushery\Billing\Contracts\DerivesDeliveryKey;
use Pushery\Billing\Contracts\WebhookEventMapper;
use Pushery\Billing\Webhooks\WebhookEffectRegistry;

/**
 * Records where the payment of a cycle in collection stands, as the driver's webhook would, where none arrived.
 *
 * A direct debit is accepted at once and settles days later, so the engine holds the cycle open, in `processing` with
 * its payment's reference, and leaves the outcome to the webhook. A webhook that never arrives, because the endpoint
 * was unreachable for longer than the provider retries, left the cycle open for good. This takes the payment's id the
 * way the provider's ping carries it and goes the way of the driver's webhook: the driver's mapper keys the standing,
 * the ledger records it, and the events go to the effects, so a paid payment settles the cycle and a failed one fails
 * it and starts the dunning. A payment still open maps to no event, and the cycle stays open.
 *
 * Reading back is safe where charging again is not. `LocalBillingEngine::releaseAbandonedClaim()` reopens a cycle for
 * which no payment was made, and is no sweep, because a second charge there would be a second debit. Here the payment
 * exists, and asking where it stands charges nothing.
 *
 * A standing the ledger already holds as handled is skipped, so a run every hour announces each change once; one whose
 * effects did not all finish is dispatched again, and the effect ledger skips every effect that did.
 */
final readonly class CyclePaymentReconciler
{
    public function __construct(
        private BillingManager $manager,
        private WebhookEventMapper $mapper,
        private WebhookEventLedger $deliveries,
        private WebhookEffectRegistry $registry,
    ) {}

    /**
     * Read the payment again and record its standing, unless the ledger already holds that standing as handled.
     *
     * @return int how many events the standing dispatched
     */
    public function reconcile(string $paymentReference): int
    {
        $request = Request::create('/', 'POST', ['id' => $paymentReference]);
        $key = $this->mapper instanceof DerivesDeliveryKey ? $this->mapper->deliveryKey($request) : null;

        // A mapper that keys no bare payment id belongs to a provider that pings otherwise, and a delivery recorded under
        // the id alone would read every later change of the payment as a redelivery of the first.
        if ($key === null || trim($key) === '') {
            return 0;
        }

        $delivery = $this->deliveries->record($this->manager->driver()->name(), trim($key), 'unknown', ['id' => $paymentReference]);

        if (! $this->deliveries->owesWork($delivery)) {
            return 0;
        }

        $dispatched = 0;

        foreach ($this->mapper->map($request) as $event) {
            $this->registry->dispatch($event, $delivery);
            $dispatched++;
        }

        $this->deliveries->markHandled($delivery);

        return $dispatched;
    }
}
