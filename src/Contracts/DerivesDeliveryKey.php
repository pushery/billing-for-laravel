<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Http\Request;

/**
 * An event mapper that knows better than the receiver what makes two deliveries the same one.
 *
 * The receiver's default key is the provider's event id, which is the right answer for a provider that
 * posts a signed event body: a redelivery repeats the id, a new occurrence brings a new one. Some
 * providers post no event at all. They ping with a bare resource id and expect the receiver to read the
 * current state back, so the SAME id arrives again every time that resource changes — and under the
 * default key every change after the first would be recognized as a redelivery and dropped. The failure
 * is silent and it is the expensive direction: an `open` payment that later turns `paid` produces one
 * recorded delivery, one effect, and no money booked.
 *
 * A mapper that implements this returns the key its provider's semantics actually require. Returning
 * null hands the decision back to the receiver, which is the correct answer for a request the mapper
 * does not recognize — a forged ping, or an id that resolves to nothing.
 *
 * This lives as its own contract rather than as a method on {@see WebhookEventMapper} because that
 * contract is public and consumers implement it: adding a method to it would be a fatal error in every
 * consumer driver that already exists. A sibling interface is opt-in by construction.
 */
interface DerivesDeliveryKey
{
    /**
     * The key a redelivery of this request is recognized by, or null to use the receiver's default.
     *
     * Implementations that need to read the provider to answer this MUST memoize that read: the receiver
     * calls this immediately before mapping, and mapping needs the same resource. Two round trips per
     * ping is a cost a redelivery storm multiplies.
     */
    public function deliveryKey(Request $request): ?string;
}
