<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\Checkout;
use Pushery\Billing\Contracts\StartsSubscriptions;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Support\SafeExternalUrl;
use Pushery\Billing\ValueObjects\SubscriptionStart;

/**
 * Starting a subscription where the PROVIDER runs the billing cycle.
 *
 * ## Why this exists rather than the screen asking `Checkout` directly
 *
 * It used to, and that was the defect: `Checkout` is bound by exactly one driver, so the Subscribe button
 * resolved an unbound interface under every other one and ended in a `BindingResolutionException`. A
 * driver that bills locally has no hosted checkout to bind, and it should not have to pretend otherwise.
 *
 * So the screen asks the contract that describes what it WANTS -- start a subscription -- and each driver
 * answers it in its own shape. Here that shape is a redirect to a hosted checkout, which is why this class
 * is thin: it is an adapter, and the Stripe behavior it adapts stays in {@see StripeCheckout} where the
 * rest of the Stripe money math lives.
 *
 * ## `Activating`, not `Trialing`, even for a trial
 *
 * The state a hosted checkout produces is not known until the customer comes back -- a trial the session
 * was configured with is still a trial the customer can abandon on the payment page. `Activating` is the
 * honest reading of the moment the redirect happens, and it is the one the account screens already poll
 * out of. Reporting a trial here would show a standing that does not exist yet.
 */
final readonly class StripeSubscriptionStarter implements StartsSubscriptions
{
    public function __construct(private Checkout $checkout, private StripeCheckout $stripe) {}

    public function start(Model $billable, string $tierKey, ?string $couponCode = null): SubscriptionStart
    {
        $intent = $this->checkout->subscribe($billable, $tierKey, $couponCode);

        // Validated here rather than at the caller, and the caller keeps its own check as well. A payload
        // is provider-shaped and this is the seam that stops being provider-shaped, so a tampered or absent
        // URL has to become a null HERE -- `SubscriptionStart::needsRedirect()` is a promise about a URL
        // somebody is going to be sent to, and it must not be able to carry a script target.
        $url = SafeExternalUrl::orNull($intent->payload['checkout_url'] ?? null);

        return new SubscriptionStart(SubscriptionState::Activating, $url);
    }

    /**
     * Whether a checkout started here would actually apply this code.
     *
     * Two conditions, and the second is the one that was missing everywhere. The catalog has to accept the
     * code, AND it has to map to a provider coupon -- because the provider is what applies the discount
     * here, and a code with no mapping reaches the session as nothing at all. A screen that asked only the
     * catalog told the customer their code took and then charged them in full, which is the same silent
     * loss the local driver's answer exists to prevent, arriving by a different route.
     */
    public function honorsCoupon(string $code): bool
    {
        return $this->stripe->providerCouponFor($code) !== null;
    }
}
