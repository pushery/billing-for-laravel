<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\ClientIntent;
use Pushery\Billing\ValueObjects\PaymentMethod;

/**
 * In-app payment-method management. setupIntent returns a driver-shaped client payload the matching
 * front-end adapter consumes (a Stripe client secret, or another provider's token or session). The
 * list/remove/set-default trio is the superset closure over apps that could only add a single card.
 *
 * The method id in the two mutating verbs is client-supplied, so an implementation MUST verify the
 * method belongs to the billable before acting — a provider detach is typically global to the method
 * id, so the provider will not check for you.
 */
interface PaymentMethods
{
    /**
     * A hosted page where the customer adds or replaces a card, or null when the driver has none (or the
     * billable has no provider customer yet). This is the package's shipped path: the card is entered on
     * the provider's own page, so no card data touches the app and no front-end JavaScript is needed. A
     * full-page redirect here is symmetric with how a subscription checkout already works.
     */
    public function addMethodUrl(Model $billable): ?string;

    /**
     * A driver-shaped client payload for capturing a method with the provider's OWN front-end element —
     * the DIY seam for an app that wants inline capture. The package ships no element for it; mount your
     * own from this payload (a Stripe client secret, or another provider's token or session). Most apps want
     * {@see addMethodUrl()} instead.
     */
    public function setupIntent(Model $billable): ClientIntent;

    /** @return list<PaymentMethod> every stored method, the default first. */
    public function all(Model $billable): array;

    public function default(Model $billable): ?PaymentMethod;

    /** Set the billable's default method. Implementations MUST verify the method belongs to the billable. */
    public function setDefault(Model $billable, string $methodId): void;

    /** Remove a stored method. Implementations MUST verify the method belongs to the billable. */
    public function remove(Model $billable, string $methodId): void;
}
