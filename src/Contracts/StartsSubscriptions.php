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
 *
 * ## The method is `start()`, not `subscribe()`, and the reason is a test double
 *
 * `Checkout::subscribe()` returns a `ClientIntent` and this one returns a `SubscriptionStart`. One class
 * cannot carry both, and one class HAS to: `BillingFake` stands in for every money seam at once, so a
 * consumer whose screen went through this contract would have found the seam it needed missing from the
 * fake with no way to add it. Naming it for what it does rather than for the button that calls it costs
 * nothing and makes the double possible.
 *
 * ## A coupon CODE rides along, and `honorsCoupon()` is why it is not a silent loss
 *
 * The code is client input like the tier key, so it is a code and never a discount amount. The reason it
 * is on this contract at all is that the alternative was measured and rejected: a screen that asks this
 * interface while only the hosted-checkout one accepts a code has to drop it on the floor, and the
 * customer then types a code, sees it accepted, and is charged full price with nothing said.
 *
 * `honorsCoupon()` closes that at the only moment where closing it is worth anything -- BEFORE the
 * customer commits. It answers for the driver that is actually configured, because the two catalogs are
 * not the same set: a hosted checkout hands the code to the provider, and a driver the package bills
 * itself can only honor a coupon the package itself holds. A screen that asks one of them about the other
 * tells the customer something that is not true of their install.
 */
interface StartsSubscriptions
{
    /**
     * Start a subscription on a configured tier.
     *
     * An optional coupon CODE (never a discount amount) is honored where the driver can honor it and
     * ignored where it cannot -- a coupon never blocks a subscription, the same direction the hosted
     * checkout takes. Ask `honorsCoupon()` first if the answer has to reach the customer.
     *
     * @throws SubscriptionNotPermitted when the tier is not one this install
     *                                  sells, or the billable already has a
     *                                  live subscription
     */
    public function start(Model $billable, string $tierKey, ?string $couponCode = null): SubscriptionStart;

    /**
     * Whether a subscription started here would actually apply this code.
     *
     * A pure question: it redeems nothing, writes nothing, and says nothing about whether the customer is
     * still entitled to the coupon when they eventually commit -- only that the code names something this
     * driver can act on. That is the claim a screen needs before it tells somebody their code took.
     */
    public function honorsCoupon(string $code): bool;
}
