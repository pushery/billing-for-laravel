<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Exceptions\SubscriptionNotPermitted;
use Pushery\Billing\ValueObjects\SubscriptionStart;

/**
 * Begin a subscription for a billable.
 *
 * ## A SIBLING of `SubscriptionActions`, not a method on it
 *
 * `SubscriptionActions` is implemented outside this package, so appending a method to it is a fatal error
 * in code the package does not own. The same reasoning that split `EstablishesMandateByRedirect` off
 * `PaymentRails` applies here, and for the same reason it is not tidiness.
 *
 * There is a second reason, and it is about meaning rather than compatibility. Everything on
 * `SubscriptionActions` acts on a subscription that EXISTS — cancel it, resume it, swap it. This one
 * brings one into being, and under a provider without a synchronous setup call it does not even do that
 * synchronously: it starts something a customer finishes in a browser. Putting it beside the others would
 * suggest it returns the same kind of certainty they do.
 *
 * ## The tier key is the only thing a caller may choose
 *
 * Never an amount, never a price id. The client sends a key, the package resolves it against the
 * configured catalog, and a key that is not in the catalog is refused. A caller that could name a price
 * could name any price, and the screen it comes from is one an untrusted party controls.
 */
interface StartsSubscriptions
{
    /**
     * Start a subscription on a configured tier.
     *
     * @throws SubscriptionNotPermitted when the tier is not one this install
     *                                  sells, or the billable already has a
     *                                  live subscription
     */
    public function subscribe(Model $billable, string $tierKey): SubscriptionStart;
}
