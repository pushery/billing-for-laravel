<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks;

use Illuminate\Support\Facades\Event;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Models\BillingWebhookEvent;

/**
 * The neutral effect bus: side-effects register against a domain-event class and run when a matching
 * event is dispatched. Effects listen on stable domain events (PaymentSucceeded,
 * SubscriptionStateChanged, …), never on provider strings, so the same effect works for every driver.
 *
 * Effects are registered by CLASS NAME, not as closures, and that is what makes the rest possible: each
 * one is dispatched as its OWN queued job. So an effect that throws no longer takes the ones after it
 * down with it (the old bus ran them in a loop, in the webhook's own HTTP request — one bad effect
 * aborted every later one and 500'd the provider), each retries on its own, and each leaves a record of
 * what it did or still owes.
 *
 * Each event is ALSO fired through Laravel's own dispatcher, so a consuming app can Event::listen for a
 * domain event or Event::fake it in a test — the package's own effects run either way. The dispatcher is
 * resolved at dispatch time, not injected: this registry is a boot-resolved singleton, and a later
 * Event::fake() swaps the container's dispatcher, so an injected one would be the pre-fake instance.
 */
final class WebhookEffectRegistry
{
    /** @var array<string, list<class-string>> */
    private array $effects = [];

    /**
     * @param  class-string<BillingDomainEvent>  $eventClass
     * @param  class-string  $effectClass  an invokable class: __invoke(BillingDomainEvent): void
     */
    public function on(string $eventClass, string $effectClass): void
    {
        $this->effects[$eventClass][] = $effectClass;
    }

    /** @return list<class-string> the effects registered for this event, in registration order. */
    public function for(BillingDomainEvent $event): array
    {
        return $this->effects[$event::class] ?? [];
    }

    /** Queue every registered effect for the event, each isolated in its own job, then fire it for the host. */
    public function dispatch(BillingDomainEvent $event, BillingWebhookEvent $delivery): void
    {
        $deliveryId = $delivery->getKey();

        foreach ($this->for($event) as $effectClass) {
            HandleWebhookEffect::dispatch(
                $effectClass,
                $event,
                $delivery->provider,
                $delivery->event_id,
                is_int($deliveryId) ? $deliveryId : null,
            );
        }

        // Fire through the framework too, so a host app can listen or fake. Resolved here, not injected,
        // so a test's Event::fake() (which rebinds the dispatcher after boot) is honored.
        Event::dispatch($event);
    }
}
