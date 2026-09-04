<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Events\MandateEstablished;
use Pushery\Billing\Models\PaymentMandate;

/**
 * Persists a granted mandate so the billing engine can charge it off-session.
 *
 * This is what makes a Mollie subscriber billable at all: the mandate is created by the customer's first
 * payment, and until it is stored the local engine finds no chargeable method and hands them to dunning
 * for a card they successfully added.
 *
 * Two properties carry the weight, and both are about repetition rather than the happy path:
 *
 * **Idempotent.** The effect is queued and the provider redelivers, so the same establishment arriving
 * twice is ordinary. `firstOrCreate` on `(provider, mandate_reference)` — the pair the table already holds
 * unique — means the second arrival returns the first row instead of giving the owner two payment methods
 * they added once.
 *
 * **The default is claimed only when there is no default.** The first mandate becomes it because there is
 * nothing else to charge; a later one must not take over, because the customer ADDED a method rather than
 * choosing to switch to it, and a silent switch bills the wrong card. Choosing is `makeDefault()`, and it
 * is a deliberate act with a screen behind it.
 */
final readonly class StoreMandate
{
    public function __construct(private CustomerDirectory $directory) {}

    public function __invoke(MandateEstablished $event): void
    {
        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model) {
            // A customer belonging to somebody else's install, or one deleted since. Resolving to nobody
            // is the answer here rather than an exception: the delivery is genuine, it is simply not ours.
            return;
        }

        $holdsDefault = PaymentMandate::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('provider', $event->provider)
            ->where('is_default', true)
            ->exists();

        PaymentMandate::query()->firstOrCreate(
            ['provider' => $event->provider, 'mandate_reference' => $event->mandateId],
            [
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'customer_reference' => $event->customerReference,
                'method' => $event->method,
                'status' => PaymentMandate::CHARGEABLE,
                'is_default' => ! $holdsDefault,
            ],
        );
    }
}
