<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\DerivesDeliveryKey;
use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\Contracts\WebhookEventMapper;
use Pushery\Billing\Contracts\WebhookVerifier;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Events\WebhookDeliveryRefused;
use Pushery\Billing\Models\BillingWebhookEvent;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\WebhookEventLedger;
use Throwable;

/**
 * The HTTP entry point of the webhook spine: verify the signature, RECORD the delivery, map the payload
 * to neutral domain events, queue each to the registered effects. Verification happens BEFORE anything
 * else is trusted, so a forged payload never reaches an effect or the ledger; a rejected request answers
 * 400. The master switch is honored first: when billing is disabled the endpoint answers 404, so a
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
            $this->announceRefusal($request);

            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $decoded = json_decode($request->getContent(), true);
        $payload = is_array($decoded) ? $decoded : [];
        $type = $payload['type'] ?? null;

        $delivery = $this->deliveries->record(
            $this->manager->driver()->name(),
            $this->deliveryKey($payload, $request),
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
     * The key this delivery is recorded and deduped under.
     *
     * Read from the REQUEST rather than only from the decoded body, because not every provider posts
     * JSON. A form-encoded ping (`id=tr_abc`) decodes to nothing, so a body-only read fell through to the
     * hash — leaving a delivery that is correct and unfindable: somebody investigating holds the provider's
     * resource id and has no route from it to the row, and `billing:replay` cannot be aimed either.
     *
     * The hash fallback stays for a body that names nothing. Removing it would leave such a delivery with
     * no key at all, which is worse than an opaque one.
     *
     * @param  array<array-key, mixed>  $payload
     */
    /**
     * The key this delivery is deduped under, asking the mapper first.
     *
     * A mapper that implements {@see DerivesDeliveryKey} knows its provider's redelivery semantics better
     * than this receiver can: a provider that pings with a bare resource id sends the SAME id every time
     * that resource changes, so the default key would recognize every change after the first as a
     * duplicate and drop it. That failure is silent and it loses money rather than double-booking it.
     *
     * The mapper returning null is not an error — it is the answer for a request it would not map — so
     * the default key stands and the delivery is still recorded exactly once.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function deliveryKey(array $payload, Request $request): string
    {
        if ($this->mapper instanceof DerivesDeliveryKey) {
            $key = $this->mapper->deliveryKey($request);

            if (is_string($key) && trim($key) !== '') {
                return trim($key);
            }
        }

        return $this->eventId($payload, $request);
    }

    /**
     * The receiver's own key: the provider's event id, or a hash of a body that names nothing.
     *
     * Read from the REQUEST rather than only from the decoded body, because not every provider posts
     * JSON. A form-encoded ping (`id=tr_abc`) decodes to nothing, so a body-only read fell through to the
     * hash — leaving a delivery that is correct and unfindable: somebody investigating holds the
     * provider's resource id and has no route from it to the row, and `billing:replay` cannot be aimed
     * either.
     *
     * The hash fallback stays for a body that names nothing. Removing it would leave such a delivery with
     * no key at all, which is worse than an opaque one.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function eventId(array $payload, Request $request): string
    {
        $id = $payload['id'] ?? $request->input('id');

        return is_string($id) && trim($id) !== '' ? $id : 'sha256:'.hash('sha256', $request->getContent());
    }

    /**
     * Say, once, that a delivery was refused — and never let saying it change the answer.
     *
     * Guarded, and the guard is the whole point on this path: a listener that throws would turn a
     * deliberate 400 into a 500, and a 5xx tells the sender "try again" about a request that can never
     * become valid. So a broken listener costs a log line and nothing else.
     *
     * Logged AS WELL as dispatched. The event is what a consumer wires an audit trail to; the log line is
     * what an install that wired nothing still gets, and shipping a security signal that is invisible
     * without setup would be the same complaint one level down. The cost is named rather than hidden: a
     * scanner hammering the endpoint writes a line per attempt, which is why it is a warning and not an
     * error.
     *
     * NOT recorded in the delivery ledger, deliberately. That table holds provider payloads for replay and
     * carries personal data; filling it from unauthenticated traffic would let anybody who can reach the
     * URL write rows into it.
     *
     * And no network address, here or on the event. This package hands an address to exactly one seam and
     * reads one nowhere else -- a promise `PlaceEvidencePrivacyTest` enforces over the whole shipped tree,
     * and one this announcement broke on its first attempt. A consumer who wants the address reads it from
     * the request in their own listener, where the decision to keep it is theirs to make.
     */
    private function announceRefusal(Request $request): void
    {
        try {
            Event::dispatch(new WebhookDeliveryRefused(
                $this->manager->driver()->name(),
                'platform',
                $request->path(),
                $request->userAgent(),
            ));
        } catch (Throwable $listener) {
            Log::warning('billing: a listener threw while a refused webhook delivery was announced', [
                'reason' => $listener->getMessage(),
            ]);
        }

        Log::warning('billing: a webhook delivery was refused by its verifier', [
            'provider' => $this->manager->driver()->name(),
            'surface' => 'platform',
            'path' => $request->path(),
        ]);
    }
}
