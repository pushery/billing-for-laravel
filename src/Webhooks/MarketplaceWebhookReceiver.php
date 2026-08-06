<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\Contracts\MarketplaceWebhookEventMapper;
use Pushery\Billing\Contracts\MarketplaceWebhookVerifier;
use Pushery\Billing\Contracts\MerchantScopedCustomerDirectory;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\WebhookEventLedger;

/**
 * The HTTP entry point for provider events about a MERCHANT.
 *
 * It is a second receiver rather than a branch inside the first, and every part of it differs: a different
 * signing secret, a different mapper, and a delivery that has to be recorded against the account it came
 * from. A single receiver switching on a header would have one place where the wrong secret could
 * authenticate the wrong traffic — and the traffic on the other endpoint moves the platform's own money.
 *
 * It answers 404 while the marketplace is off, exactly as the platform endpoint does while billing is off:
 * an endpoint that accepted and discarded merchant events would look configured and do nothing, which is
 * the failure the whole ticket is about.
 */
final readonly class MarketplaceWebhookReceiver
{
    public function __construct(
        private BillingManager $manager,
        private MarketplaceWebhookVerifier $verifier,
        private MarketplaceWebhookEventMapper $mapper,
        private WebhookEffectRegistry $registry,
        private WebhookEventLedger $deliveries,
        private MerchantScopedCustomerDirectory $customers,
    ) {}

    public function __invoke(Request $request): Response
    {
        if (! $this->manager->enabled() || ! $this->manager->marketplaceEnabled()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if (! $this->verifier->verify($request)) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $decoded = json_decode($request->getContent(), true);
        $payload = is_array($decoded) ? $decoded : [];

        $account = $payload['account'] ?? null;
        $type = $payload['type'] ?? null;

        // An event with no account is not attributable to any merchant. Recording it under the platform's
        // own empty account would file merchant traffic where platform traffic lives, and its effects would
        // then have no merchant to act on. Accepted (so the provider stops retrying) and dropped.
        if (! is_string($account) || $account === '') {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $delivery = $this->deliveries->record(
            $this->manager->driver()->name(),
            $this->eventId($payload, $request),
            is_string($type) ? $type : 'unknown',
            $payload,
            $account,
        );

        foreach ($this->mapper->map($request) as $event) {
            // Attribute the stored delivery to the buyer it is about, so an erasure request can reach the
            // personal data in its payload. Resolved INSIDE the account: a global lookup would hand back
            // whichever person happens to hold that customer id first, and attaching a stranger's identity
            // to somebody else's payload is worse than attaching none.
            if ($event instanceof IdentifiesCustomer) {
                $owner = $this->customers->ownerForReference($account, $event->customerReference);

                if ($owner instanceof Model) {
                    $this->deliveries->attachOwner($delivery, $owner);
                }
            }

            $this->registry->dispatch($event, $delivery);
        }

        $this->deliveries->markHandled($delivery);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * The provider's event id. A payload carrying none falls back to a hash of the body, so an
     * unidentifiable delivery is still recorded once rather than a row per retry.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function eventId(array $payload, Request $request): string
    {
        $id = $payload['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : 'sha256:'.hash('sha256', $request->getContent());
    }
}
