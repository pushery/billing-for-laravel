<?php

declare(strict_types=1);

namespace Pushery\Billing\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Pushery\Billing\Contracts\BillingEntityResolver;
use Pushery\Billing\Contracts\HostedPortal;
use Pushery\Billing\Support\SubscriptionReconciler;
use Throwable;

/**
 * The hosted-portal bridge and the checkout return: redirects the signed-in owner to the provider's own
 * billing portal, and lands them back from a hosted checkout with their subscription already reconciled.
 * When no portal is available (the driver has none, or the owner has no provider customer yet) the portal
 * answers 404, so the app can fall back to the in-app account-hub screens.
 */
final class BillingController
{
    public function portal(): RedirectResponse
    {
        $actor = Auth::user();

        abort_unless($actor instanceof Model, 403);

        $owner = app(BillingEntityResolver::class)->ownerFor($actor);
        $url = app(HostedPortal::class)->url($owner);

        abort_if($url === null, 404);

        return redirect()->away($url);
    }

    /**
     * The hosted-checkout return URL. The customer is back from the provider — possibly before the webhook
     * arrived — so the subscription is reconciled onto the local row NOW, then they are sent to the
     * subscription screen. A failed reconcile is reported and swallowed: the webhook is the durable path,
     * and a customer must never be shown an error page after a successful payment.
     */
    public function checkoutReturn(): RedirectResponse
    {
        $actor = Auth::user();

        abort_unless($actor instanceof Model, 403);

        $owner = app(BillingEntityResolver::class)->ownerFor($actor);

        try {
            app(SubscriptionReconciler::class)->syncFromProvider($owner);
        } catch (Throwable $e) {
            report($e);
        }

        return redirect()->route('billing.account.subscription');
    }
}
