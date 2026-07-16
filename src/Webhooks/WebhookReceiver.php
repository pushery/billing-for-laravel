<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\Contracts\WebhookEventMapper;
use Pushery\Billing\Contracts\WebhookVerifier;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Models\BillingWebhookEvent;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\WebhookEventLedger;

/**
 * The HTTP entry point of the webhook spine: verify the signature, RECORD the delivery, map the payload
 * to neutral domain events, queue each to the registered effects. Verification happens BEFORE anything
 * else is trusted, so a forged payload never reaches an effect or the ledger; a rejected request answers
 * 400. The master switch is honoured first: when billing is disabled the endpoint answers 404, so a
 * paused clone mutates no state and sends no dunning even if a secret is still configured.
 *
 * The delivery is recorded WITH its raw payload before any effect runs. That record is what makes the
 * package recoverable: an effect that fails can be re-driven from the stored payload later
 * (`billing:webhooks:replay`), rather than depending on the provider to redeliver — which it stops doing
 * once its own retry window closes.
 *
 * Effects are queued, not run here, so the provider gets its 204 immediately and a slow (or failing)
 * effect can neither hold the request open nor turn into a 500 the provider reads as our outage.
 */
final readonly class WebhookReceiver
{
    public function __construct(
        private BillingManager $manager,
        private WebhookVerifier $verifier,
        private WebhookEventMapper $mapper,
        private WebhookEffectRegistry $registry,
        private WebhookEventLedger $deliveries,
        private CustomerDirectory $customers,
    ) {}

    public function __invoke(Request $request): Response
    {
        if (! $this->manager->enabled()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if (! $this->verifier->verify($request)) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $decoded = json_decode($request->getContent(), true);
        $payload = is_array($decoded) ? $decoded : [];
        $type = $payload['type'] ?? null;

        $delivery = $this->deliveries->record(
            $this->manager->driver()->name(),
            $this->eventId($payload, $request),
            is_string($type) ? $type : 'unknown',
            $payload,
        );

        foreach ($this->mapper->map($request) as $event) {
            $this->attribute($delivery, $event);

            $this->registry->dispatch($event, $delivery);
        }

        $this->deliveries->markHandled($delivery);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Attribute the stored delivery to the owner it is about, so an erasure request can reach it.
     *
     * The payload this receiver keeps carries that owner's personal data — email, name, billing address,
     * card last four. A delivery with no owner is personal data nobody can find, and a right to erasure
     * that cannot reach the data is not a right to erasure.
     */
    private function attribute(BillingWebhookEvent $delivery, BillingDomainEvent $event): void
    {
        if (! $event instanceof IdentifiesCustomer) {
            return;
        }

        $owner = $this->customers->ownerForReference($event->customerReference);

        if ($owner instanceof Model) {
            $this->deliveries->attachOwner($delivery, $owner);
        }
    }

    /**
     * The provider's event id — the identity a redelivery is recognized by. A payload carrying none falls
     * back to a hash of the body, so an unidentifiable delivery is still recorded exactly once rather
     * than piling up a row per retry.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function eventId(array $payload, Request $request): string
    {
        $id = $payload['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : 'sha256:'.hash('sha256', $request->getContent());
    }
}
